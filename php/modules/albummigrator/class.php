<?php
namespace Slimpd;

/**
 * idea behind this freaky code construct:
 * 
 * fire tons of regexes against every collected attribute
 * based on those RegEx-result add or remove attribute-value score
 * the attribute-value with the highest score wins and will be migrated
 * 
 * of course this fucks up some attributes but the majority of invalid tagged files will profit enourmously
 * 
 * TODO: make whole guessing stuff optional or semi-optional 
 * TODO: handle disc thingy on tracks and albums
 * TODO: add trackCount immediately and remove it from importer-phase?8?
 * TODO: add isMixed attrribute based on
 * 	* configured mix-directory config_local.ini
 *  * common strings like "mixed by" in directory name
 *  * cue-sheet with same filename as musicfile
 *  * music file duration greater than X
 * 
 * TODO (outside of this class): create a a link in gui for remigrating a single album
 *
 * TODO: override label-attribute (based on dirs in config_local.ini) immediatly and remove it from importer phase ?7?
 */

class AlbumMigrator {
	protected $relativeDirectoryPathHash;
	protected $relativeDirectoryPath;
	public $directoryMtime;
	
	protected $tracks;
	
	protected $handleAsAlbum = NULL;
	protected $handleAsAlbumScore = 0;
	
	// pluralized array_keys of relevant rawTagData-array
	protected $artists;
	protected $albums;
	protected $albumArtists;
	protected $genres;
	protected $comments;
	protected $years;
	protected $labels;
	protected $catalogNumbers;
	protected $discogsReleaseIds;
	protected $sources;
	protected $urls;
	
	
	protected $mimeTypes;
	protected $audioBitrates;
	protected $audioBitrateModes;
	protected $audioSampleRates;
	protected $audioDataformats;
	protected $audioEncoders;
	protected $audioLosslesss;
	
	
	protected $totalTrackss;
	
	
	protected $filenameCases;
	
	// extracted schemes for each track
	protected $filenameSchemes;
	protected $titleSchemes;
	protected $artistSchemes;
	protected $albumSchemes;
	protected $numberSchemes;
	
	// unified ordered by relevance
	protected $uniRelFilenameSchemes;
	protected $uniRelTitleSchemes;
	protected $uniRelArtistSchemes;
	protected $uniRelAlbumSchemes;
	protected $uniRelNumberSchemes;
	
	
	protected $extractedTrackNumbers;
	protected $extractedTotalTracks;
	
	
	// recommendations
	protected $r;
	
	// attribute with highest score from recommendations
	protected $mostScored = array();
	protected $defaultScoreForRealTagAttrs = 5;
	
	
	private function getMostScored($idx, $attrName) {
		if(isset($this->r[$idx][$attrName]) === FALSE) {
			return '';
		}
		$highestScore = array_keys($this->r[$idx][$attrName], max($this->r[$idx][$attrName]));
		if(count($highestScore) === 1) {
			return $highestScore[0];
		}
		
		// hard to decide what to take if we have same score for different values
		// lets take the longest string :)
		
		$lengths = array_map('strlen', $highestScore);
		$maxLength = max($lengths);
		$index = array_search($maxLength, $lengths);
		return $highestScore[$index];		 
	}
	
	


	
	
	public function run() {
		
		
		
		// first of all - try to guess if this dir should be
		// treated as an album or as a bunch of loose tracks
		// further this method is adding score to several attributes which will be migrated to production db-table
		$this->setHandleAsAlbum();
		#print_r($this->r);
		
		cliLog("handleAsAlbumScore " . $this->handleAsAlbumScore , 3, 'purple'); #die();
		
		
		#if($this->tracks[0]['relativePath'] == 'newroot/crse002cd--Calibre-Musique_Concrete-2CD-CRSE002CD-2001-sour/101-calibre-deep_everytime.mp3') {
			#print_r($this->r); die();
		#}
		
		
		// extract some attributes from tracks
		// those will be used for album stuff
		$mergedFromTracks = array(
			'artist' => array(),
			'genre' => array(),
			'label' => array(),
			'catalogNr' => array(),
		);
		foreach(array_keys($mergedFromTracks) as $what) {
			foreach($this->tracks as $idx => $rawTagData) {
				$mergedFromTracks[$what][] = $this->getMostScored($idx, $what);
			}
			$mergedFromTracks[$what][] = $this->getMostScored('album', $what);
			$mergedFromTracks[$what] = join(',', array_unique($mergedFromTracks[$what]));
		}
		$albumArtists = (count(trimExplode(",", $mergedFromTracks['artist'])) > 3)
			? 'Various Artists'
			: $mergedFromTracks['artist'];
		
		$a = new Album();
		
		$a->setArtistId(join(",", Artist::getIdsByString($albumArtists)));
		$a->setGenreId(join(",", Genre::getIdsByString($mergedFromTracks['genre'])));
		#$a->setLabelId(join(",", Label::getIdsByString($mergedFromTracks['label'])));
		$a->setCatalogNr($this->mostScored['album']['catalogNr']);
		
		$a->setRelativePath($this->getRelativeDirectoryPath());
		$a->setRelativePathHash($this->getRelativeDirectoryPathHash());
		$a->setAdded($this->getDirectoryMtime());
		$a->setFilemtime($this->getDirectoryMtime());
		
		$a->setTitle($this->mostScored['album']['title']);
		$a->setYear($this->mostScored['album']['year']);
		
		$a->setIsJumble(($this->handleAsAlbum === TRUE) ? 0:1);
		
		$a->setTrackCount(count($this->tracks));
		
		#print_r($a); die();
		$a->update();
		
		$albumId = $a->getId();
		
		// add the whole bunch of valid and indvalid attributes to albumindex table
		$this->updateAlbumIndex($albumId);
		
		
		
		foreach($this->tracks as $idx => $rawTagData) {
			$t = $this->migrateNonGuessableData($rawTagData);
			
			
			
			$t->setArtistId($this->mostScored[$idx]['artist']); // currently the string insted of an artistId
			$t->setTitle($this->mostScored[$idx]['title']);
			
			$t->setFeaturedArtistsAndRemixers();
				# setFeaturedArtistsAndRemixers() is processing:
				# $t->setArtistId();
				# $t->setFeaturingId();
				# $t->setRemixerId();
				
			$t->setGenreId(join(",", Genre::getIdsByString($this->getMostScored($idx, 'genre'))));
			$t->setLabelId(join(",", Label::getIdsByString($this->getMostScored($idx, 'label'))));
			
			$t->setCatalogNr($this->mostScored[$idx]['catalogNr']);
			
			$t->setDisc($this->mostScored[$idx]['disc']);
			$t->setNumber($this->mostScored[$idx]['number']);
			
			$t->setComment($this->mostScored[$idx]['comment']);
			$t->setYear($this->mostScored[$idx]['year']);
			
			
			
			
			
			$t->setAlbumId($albumId);
			
			// make sure to use identical ids in table:rawtagdata and table:track
			\Slimpd\Track::ensureRecordIdExists($t->getId());
			$t->update();
			
			// make sure extracted images will be referenced to an album
			\Slimpd\Bitmap::addAlbumIdToTrackId($t->getId(), $albumId);#
			
			
			// add the whole bunch of valid and indvalid attributes to trackindex table
			$this->updateTrackIndex($t->getId(), $idx);
				
		}
		
		unset($this->r['album']);
		
		if($this->handleAsAlbum === TRUE) {
		
			// try to guess if all tracks of this album has obviously invalid fixable attributes
			
		} 
		
		return;
		print_r($this->r); #die();

	}

	private function updateTrackIndex($trackId, $idx) {
		$indexChunks = $this->tracks[$idx]['relativePath'] . " ";
		
		if(isset($this->r[$idx]) === TRUE) {
			foreach($this->r[$idx] as $attrType => $scoreCombo) {
				$indexChunks .= join(" ", array_keys($scoreCombo)) . " ";
			}
		}
		if(isset($this->r['album']) === TRUE) {
			foreach($this->r['album'] as $attrType => $scoreCombo) {
				$indexChunks .= join(" ", array_keys($scoreCombo)) . " ";
			}
		}
		$indexChunks .= join(" ", $this->mostScored[$idx]) . " ";
		$indexChunks .= join(" ", $this->mostScored['album']) . " ";
		$indexChunks .= str_replace(
			array('/', '_', '-', '.'),
			' ',
			$this->tracks[$idx]['relativePath']
		);
		// make sure to use identical ids in table:trackindex and table:track
		\Slimpd\Trackindex::ensureRecordIdExists($trackId);
		$ti = new \Slimpd\Trackindex();
		$ti->setId($trackId);
		$ti->setArtist($this->mostScored[$idx]['artist']);
		$ti->setTitle($this->mostScored[$idx]['title']);
		$ti->setAllchunks($indexChunks);
		$ti->update();
	}


	private function updateAlbumIndex($albumId) {
		$indexChunks = $this->tracks[0]['relativeDirectoryPath'] . " ";
		if(isset($this->r['album']) === TRUE) {
			foreach($this->r['album'] as $attrType => $scoreCombo) {
				$indexChunks .= join(" ", array_keys($scoreCombo)) . " ";
			}
		}
		$indexChunks .= join(" ", $this->mostScored['album']) . " ";
		$indexChunks .= str_replace(
			array('/', '_', '-', '.'),
			' ',
			$this->tracks[0]['relativeDirectoryPath']
		);
		// make sure to use identical ids in table:trackindex and table:track
		\Slimpd\Albumindex::ensureRecordIdExists($albumId);
		$ti = new \Slimpd\Albumindex();
		$ti->setId($albumId);
		$ti->setArtist($this->mostScored['album']['artist']);
		$ti->setTitle($this->mostScored['album']['title']);
		$ti->setAllchunks($indexChunks);
		$ti->update();
	}

	/**
	 * no guessing - if value seems reasonable or not - required
	 */
	public function migrateNonGuessableData($rawArray) {
		
		$t = new \Slimpd\Track();
		$t->setId($rawArray['id']);
		$t->setRelativePath($rawArray['relativePath']);
		$t->setRelativePathHash($rawArray['relativePathHash']);
		$t->setDirectoryPathHash($rawArray['relativeDirectoryPathHash']);
		$t->setFingerprint($rawArray['fingerprint']);
		$t->setMimeType($rawArray['mimeType']);
		$t->setFilesize($rawArray['filesize']);
		$t->setFilemtime($rawArray['filemtime']);
		$t->setMiliseconds(round($rawArray['miliseconds']*1000));
		$t->setAudioDataformat($rawArray['audioDataformat']);
		$t->setAudioCompressionRatio($rawArray['audioCompressionRatio']);
		$t->setAudioEncoder(($rawArray['audioEncoder']) ? $rawArray['audioEncoder'] : 'Unknown encoder');
		if ($rawArray['audioLossless']) {
			$t->setAudioLossless($rawArray['audioLossless']);
			if ($rawArray['audioCompressionRatio'] == 1) {
				$t->setAudioProfile('Losless');
			} else {
				$t->setAudioProfile('Losless compression');
			}
		}
		$t->setAudioBitrate(round($rawArray['audioBitrate'])); // integer in database
		if(!$t->getAudioProfile()) {
			$t->setAudioProfile($rawArray['audioBitrateMode'] . " " . round($t->getAudioBitrate()/ 1000, 1) . " kbps");
		}
		$t->setAudioBitsPerSample(($rawArray['audioBitsPerSample'] ? $rawArray['audioBitsPerSample'] : 16));
		$t->setAudioSampleRate(($rawArray['audioSampleRate'] ? $rawArray['audioSampleRate'] : 44100));
		$t->setAudioChannels(($rawArray['audioChannels'] ? $rawArray['audioChannels'] : 2));

		$t->setVideoDataformat($rawArray['videoDataformat']);
		$t->setVideoCodec($rawArray['videoCodec']);
		$t->setVideoResolutionX($rawArray['videoResolutionX']);
		$t->setVideoResolutionY($rawArray['videoResolutionY']);
		$t->setVideoFramerate($rawArray['videoFramerate']);
		
		$t->setImportStatus($rawArray['importStatus']);
		$t->setLastScan($rawArray['lastScan']);
		
		$t->setError($rawArray['error']);
		$t->setDr($rawArray['dynamicRange']);
		return $t;
	}
	
	private function postProcessRecommendations() {
		$attrNames = array(
			'artist',
			'title',
			'year',
			'catalogNr',
			'label',
			'source',
			'number',
			'disc',
			'genre',
			'comment',
			'album',
		);
		foreach($attrNames as $attrName) {
			foreach($this->tracks as $idx => $foo) {
				$this->mostScored[$idx][$attrName] = $this->getMostScored($idx, $attrName);
			}
			$this->mostScored['album'][$attrName] = $this->getMostScored('album', $attrName);
			
		}
		$rx = new \Slimpd\RegexHelper();
		
		
		// last fixes :)                                   hopefully...
		foreach($this->mostScored as $idx => $item) {
			if($idx === 'album') {
				continue;
			}
			#cliLog("/^". preg_quote($item['artist']).$rx->glue ."/i");
			// remove artist from title in case title starts with artists
			// A: Little Legends, The
			// T: Little Legends, The - Swamp Walk
			if($item['artist'] !== '' && preg_match("/^". preg_quote($item['artist'], "/").$rx->glue ."(.{5,})/i", $item['title'], $m)) {
				#print_r($item); #die();
				$this->mostScored[$idx]['title'] = remU($m[1]);
			}
			
			// in case album:title == artist take 2nd scored
			//if($this->tracks[$idx]['artist'] == $item['album']
			//&& $this->tracks[$idx]['artist'] !== ''
			//&& preg_match("/^".$rx->noMinus.$rx->glue .preg_quote($item['title'], "/")."$/i", $this->tracks[$idx]['title'], $m)) {
			//	print_r($m); #die();
			//	// match -> neuer artist
			//	#print_r($this->r[$idx]); die();
			//	$this->mostScored[$idx]['artist'] = trim($m[1]); 
			//}
		}
	}
	
	
	
	private function setHandleAsAlbum() {
		

		// collect specific data for comparison
		foreach($this->tracks as $idx => $t) {
			
			$this->artists[$idx] = $t['artist'];
			$this->albums[$idx] = $t['album'];
			$this->albumArtists[$idx] = $t['albumArtist'];
			$this->genres[$idx] = $t['genre'];
			$this->comments[$idx] = $t['comment'];
			$this->years[$idx] = $t['year'];
			$this->labels[$idx] = $t['publisher'];
			$this->catalogNumbers[$idx] = $t['textCatalogNumber'];
			$this->discogsReleaseIds[$idx] = $t['textDiscogsReleaseId'];
			$this->sources[$idx] = $t['textSource'];
			$this->urls[$idx] = $t['textUrlUser'];
			
			$this->mimeTypes[$idx] = $t['mimeType'];
			$this->audioBitrates[$idx] = $t['audioBitrate'];
			$this->audioBitrateModes[$idx] = $t['audioBitrateMode'];
			$this->audioSampleRates[$idx] = $t['audioSampleRate'];
			$this->audioDataformats[$idx] = $t['audioDataformat'];
			$this->audioEncoders[$idx] = $t['audioEncoder'];
			$this->audioLosslesss[$idx] = $t['audioLossless'];
			
			$this->totalTrackss[$idx] = $t['totalTracks'];
			
			$this->filenameCases[$idx] = $this->getFilenameCase( basename($t['relativePath']) );
			
			$this->filenameSchemes[$idx] = $this->getFilenameScheme( basename($t['relativePath']), $idx);
			$this->artistSchemes[$idx] = $this->getArtistOrTitleScheme($t['artist'], $idx, 'artist'); // we can use the same
			$this->titleSchemes[$idx] = $this->getArtistOrTitleScheme($t['title'], $idx, 'title');
			$this->albumSchemes[$idx] = $this->getAlbumScheme($t['album'], $idx);
			$this->numberSchemes[$idx] = $this->getNumberScheme($t['trackNumber'], $idx);
			
			
			// add score for real unmodified attributes
			$this->scoreAttribute($idx, 'artist',    $t['artist'], $this->defaultScoreForRealTagAttrs);
			$this->scoreAttribute($idx, 'artist',    $t['albumArtist'], $this->defaultScoreForRealTagAttrs);
			$this->scoreAttribute($idx, 'title',     $t['title'], $this->defaultScoreForRealTagAttrs);
			$this->scoreAttribute($idx, 'genre',     $t['genre'], $this->defaultScoreForRealTagAttrs);
			$this->scoreAttribute($idx, 'comment',   $t['comment'], $this->defaultScoreForRealTagAttrs);
			$this->scoreAttribute($idx, 'year',      $t['year'], $this->defaultScoreForRealTagAttrs);
			$this->scoreAttribute($idx, 'label',     $t['publisher'], $this->defaultScoreForRealTagAttrs);
			$this->scoreAttribute($idx, 'catalogNr', $t['textCatalogNumber'], $this->defaultScoreForRealTagAttrs);
			$this->scoreAttribute($idx, 'discogsId', $t['textDiscogsReleaseId'], $this->defaultScoreForRealTagAttrs);
			$this->scoreAttribute($idx, 'source',    $t['textSource'], $this->defaultScoreForRealTagAttrs);
			$this->scoreAttribute($idx, 'urlUser',   $t['textUrlUser'], $this->defaultScoreForRealTagAttrs);
			$this->scoreAttribute('album', 'title',  $t['album'], $this->defaultScoreForRealTagAttrs);
			$this->scoreAttribute('album', 'artist', $t['albumArtist'], $this->defaultScoreForRealTagAttrs);
			$this->scoreAttribute('album', 'artist', $t['artist'], $this->defaultScoreForRealTagAttrs);

			
			
		}
		$this->guessAttributesByDirectoryName($t['relativeDirectoryPath']);

		// TODO: post procession:
		$this->postProcessRecommendations();
		// in case bestartistmatch == album title take the 2nd best match for track artist  
		

		
		// check similarity of collected data
		$trackCount = count($this->tracks);
		
		// TODO: check if we should move this to top of method...
		if($trackCount == 1) {
			$this->handleAsAlbum = TRUE;
			return;
		}
		
		# define some weightening
		# TODO: testing, testing, testing - lets see if those values makes sense
		$scoreTable = array(
			'artists' => 0.7,
			'albums' => 5,
			'albumArtists' => 1,
			'genres' => 2,
			'comments' => 1,
			'years' => 2,
			'labels' => 1,
			'catalogNumbers' => 4,
			'discogsReleaseIds' => 1,
			'sources' => 1,
			'urls' => 1,
			
			'mimeTypes' => 1,
			'audioBitrates' => 1, 
			'audioBitrateModes' => 1,
			'audioSampleRates' => 1,
			'audioDataformats' => 1,
			'audioEncoders' => 1,
			'audioLosslesss' => 1,
			
			'totalTrackss' => 3,
			
			'filenameCases' => 2,
			
			'filenameSchemes' => 3,
			'titleSchemes' => 3,
			'artistSchemes' => 3,
			'albumSchemes' => 3,
			'numberSchemes' => 2,
		);
		
		$decisionBoundry = 11;
		
		foreach(array_keys($scoreTable) as $property) {
			$bestMatch = uniqueArrayOrderedByRelevance($this->$property);
			switch($property) {
				case 'filenameSchemes':
				case 'titleSchemes':
				case 'artistSchemes':
				case 'albumSchemes':
				case 'numberSchemes':
					$uniRelProp = 'iniRel' . ucfirst($property);
					$this->$uniRelProp = $bestMatch;
					break;
				default :
					break;
			}

			#cliLog("bestmatch" . $bestMatch , 1, 'purple'); #die();
			$propScore = $scoreTable[$property];
			
			foreach($this->$property as $i) {
				// does it make sense to exclude missing attributes from scoring?
				if($i == '' && count($bestMatch) == 1) {
					continue;
				}
				// it makes no sense to compare bitrates on vbr's
				if($property === 'audioBitrateModes' && $this->audioBitrateModes[$idx] === 'vbr') {
					continue;
				}
				$this->handleAsAlbumScore += ($i == $bestMatch[0]) ? $propScore : $propScore*-1;
			}
		}
		
		$this->addChronologyScore();
		
		$this->handleAsAlbumScore /= $trackCount;
		$this->handleAsAlbum = ($this->handleAsAlbumScore>$decisionBoundry) ? TRUE : FALSE;
		return;
	}

	/**
	 * can be used for processing artist or title tag
	 * TODO: remove title-score for "AudioTrack XX", "Unbekannter Titel", "Track XX", "Piste XX"
	 */
	
	private function getArtistOrTitleScheme($value, $idx, $artistOrTitle) {
		$value = remU($value);
		if($value == '') {
			return 'missing';
		}
		
		$rx = new \Slimpd\RegexHelper();
		
		$iHateRegex = array(
			// 01 - Super Tracktitle
			'prefixed-number' => $rx->dStart.$rx->mayBracket.$rx->num.$rx->mayBracket.$rx->glue.$rx->noMinus.$rx->dEndInsens,
			// B2. Super Tracktitle
			'prefixed-vinyl' => $rx->dStart.$rx->mayBracket.$rx->vinyl.$rx->mayBracket.$rx->glue.$rx->noMinus.$rx->dEndInsens,
			
			// Artist - Super Tracktitle
			'artist-title' => $rx->dStart.$rx->noMinus."-".$rx->noMinus.$rx->dEnd,
			// 01 - Artist - Super Tracktitle
			'prefixed-number-artist-title' => $rx->dStart.$rx->num.$rx->glue.$rx->noMinus."-".$rx->noMinus.$rx->dEndInsens,
			// B2.-Artist - Super Tracktitle
			'prefixed-vinyl-artist-title' => $rx->dStart.$rx->vinyl.$rx->glue.$rx->noMinus."-".$rx->noMinus.$rx->dEndInsens,
			
			// Super Tracktitle
			'classic' => $rx->dStart.$rx->noMinus.$rx->dEndInsens,
		);
		foreach($iHateRegex as $result => $pattern) {
			if(preg_match($pattern, $value, $m)) {
				switch($result) {
					// make recommendations for each track
					case 'classic':
						$this->recommend($idx, array($artistOrTitle => $m[1]));
						break;
					case 'prefixed-number':
					case 'prefixed-vinyl':
						#print_r($m); die($result);
						$this->recommend($idx, array(
							'number' => $m[2],
							$artistOrTitle => $m[4]
						));
						#$this->scoreAttribute($idx, $artistOrTitle, $value, ($this->defaultScoreForRealTagAttrs*(-1)));
						break;
					case 'artist-title':
						$this->recommend($idx, array(
							'artist' => $m[1],
							'title' => $m[2]
						));
						break;
					case 'prefixed-number-artist-title':
					case 'prefixed-vinyl-artist-title':
						$this->recommend($idx, array(
							'number' => $m[1],
							'artist' => $m[2],
							'title' => $m[3]
						));
						break;
				}
				if(stripos($result, 'vinyl') !== FALSE) {
					$this->recommend($idx, array('source' => 'Vinyl'));
				}
				cliLog(__FUNCTION__ ." ".$result .": " . $value ,6 , 'red');
				return $result;
			} else {
				$this->recommend($idx, array($artistOrTitle => $value));
			}
		}
		
		$result = "nomatch";
		#cliLog(__FUNCTION__ ." ".$result .": " . $value ,1 , 'red');
		return $result;
	}

	private function guessAttributesByDirectoryName($path) {
		$value = basename($path);
		
		if(preg_match("/^cd\d+$/i", $value)) {
			// one level up in case directory is named "cd1", "cd23",...
			return $this->guessAttributesByDirectoryName(basename(dirname($value)));
		}
		
		$rx = new \Slimpd\RegexHelper();
		#$value = "Tony_Tuff-Tony_Tuff_1980";
		#$value = "Tony_Tuff-Tony_Tuff_(ESO1980";
		#$value = "Tony_Tuff-Tony_Tuff"; 
		$iHateRegex = array(
			// VA-Congo_Sevens_Number_1
			'artist-title-year' => $rx->dStart.$rx->noMinus.$rx->glue.$rx->noMinus.$rx->glue.$rx->mayBracket.$rx->year.$rx->mayBracket.$rx->dEnd,
			
			// VA-Congo_Sevens_Number_1
			'artist-title' => $rx->dStart.$rx->noMinus.$rx->glue.$rx->noMinus.$rx->dEnd,
			
		);
		foreach($iHateRegex as $result => $pattern) {
			#cliLog($pattern);
			#cliLog($value);
			if(preg_match($pattern, $value, $m)) {
				switch($result) {
					case 'artist-title-year':
						#print_r($m); die($result);
						$this->recommend('album', array(
							'artist' => remU($m[1]),
							'title' => remU($m[2]),
							'year' => remU($m[3]),
						));
						break;
					case 'artist-title':
						#print_r($m); die($result);
						$this->recommend('album', array(
							'artist' => remU($m[1]),
							'title' => remU($m[2])
						));
						break;
				}
				if(stripos($result, 'vinyl') !== FALSE) {
					$this->recommend($idx, array('source' => 'Vinyl'));
				}
				#cliLog(__FUNCTION__ ." ".$result .": " . $value ,1 , 'red');
				#return $result;
			} else {
				#cliLog('nomatch for ' . $value);
				#die();
				
			}
		}
		
		$this->recommend('album', array('title' => remU($value)));
		
		if(preg_match_all("/".$rx->mayBracket.$rx->year.$rx->mayBracket."/", $value, $m)) {
			foreach($m as $i) {
				foreach($i as $x) {
					$this->scoreAttribute('album', 'year', $x);
					$this->scoreAllTracksWithAttribute('year', $x);
				}
			}
		}
		if(preg_match_all("/".$rx->catNr."/", $value, $m)) {
			foreach($m as $i) {
				foreach($i as $x) {
					$this->scoreAttribute('album', 'catalogNr', $x);
					$this->scoreAllTracksWithAttribute('catalogNr', $x);
				}
			}
		}
		// for my personal collection - pretty sure this is not very common
		if(preg_match("/^([a-z0-9_]{1,15})\-\-/", $value, $m)) {
			$this->scoreAttribute('album', 'catalogNr', $m[1], 2);
			$this->scoreAllTracksWithAttribute('catalogNr', $m[1], 2);
		}
	}


	private function recommend($idx, $attrArray, $score = 1) {
		$rx = new \Slimpd\RegexHelper();
		
		foreach($attrArray as $attrName => $attrValue) {
			$this->scoreAttribute($idx, $attrName, $attrValue, $score);
		}
		if(isset($attrArray['artist']) && self::isVA($attrArray['artist']) === TRUE) {
			$this->scoreAttribute($idx, 'artist', 'Various Artists', -1);
			$this->scoreAttribute($idx, 'artist', $attrArray['artist'], -2);
			$this->scoreAttribute('album', 'artist', 'Various Artists', 1);

		}
		
		// do a special scoring on splitted title if we find "various artists" in artist
		if(isset($attrArray['title']) === TRUE && isset($this->r[$idx]['artist']['Various Artists']) == TRUE) {
			#die('sg');
			foreach(range(5,1) as $len) {
				if(preg_match("/^".$rx->anything."([ .\/\-_]{".$len."})".$rx->anything."$/", $attrArray['title'],$m)) {
					$this->scoreAttribute($idx, 'artist', $m[1], 3);
					$this->scoreAttribute($idx, 'title', $m[3], 3);
					break;
				}
			}
		}
		
		
		// remove various artists in case we find it in albumtitle
		if($idx == 'album' && isset($attrArray['title'])) {
			if(preg_match("/".$rx->va.$rx->glue. $rx->anything.$rx->dEndInsens, $attrArray['title'], $m)) {
				#print_r($m);
				$this->scoreAttribute('album', 'title', $m[2], 3);
				$this->scoreAttribute('album', 'title', $attrArray['title'], -2);
			}
		}

		// one more sheme parsing of artist or title attribute
		if(isset($attrArray['title']) || isset($attrArray['artist'])) {
			
			#TODO : da weitermachen
			foreach(array("artist", "title") as $prop) {
				if(isset($attrArray[$prop]) === FALSE) {
					continue;
				}
				#cliLog($attrArray[$prop]);
				#cliLog($rx->dStart.$rx->num.$rx->glue.$rx->noMinus.$rx->glue.$rx->noMinus.$rx->dEndInsens);
				if(preg_match($rx->dStart.$rx->num.$rx->glue.$rx->noMinus.$rx->glue.$rx->noMinus.$rx->dEndInsens, $attrArray[$prop], $m)) {
					$this->scoreAttribute($idx, 'number', $m[1], 3);
					$this->scoreAttribute($idx, 'artist', $m[2], 3);
					$this->scoreAttribute($idx, 'title', $m[3], 3);
					$this->scoreAttribute($idx, $prop, $attrArray[$prop], -2);
				}
				if(preg_match($rx->dStart.$rx->vinyl.$rx->glue.$rx->noMinus.$rx->glue.$rx->noMinus.$rx->dEndInsens, $attrArray[$prop], $m)) {
					$this->scoreAttribute($idx, 'number', $m[1], 3);
					$this->scoreAttribute($idx, 'artist', $m[2], 3);
					$this->scoreAttribute($idx, 'title', $m[3], 3);
					$this->scoreAttribute('album', 'source', 'Vinyl', 3);
					$this->scoreAttribute($idx, $prop, $attrArray[$prop], -2);
				}
				
				// Ahmad Jamal with Voices-1967-Cry Young-04-Who Needs Manhattan
				if(preg_match($rx->dStart.$rx->noMinus.$rx->glue.$rx->year.$rx->glue.$rx->noMinus.$rx->glue.$rx->num.$rx->glue.$rx->noMinus.$rx->dEndInsens, $attrArray[$prop], $m)) {
					#print_r($m); die();
					$this->scoreAttribute($idx, 'artist', $m[1], 3);
					$this->scoreAttribute($idx, 'year', $m[2], 3);
					$this->scoreAttribute('album', 'year', $m[2], 3);
					$this->scoreAttribute('album', 'title', $m[3], 3);
					$this->scoreAttribute($idx, 'number', $m[4], 3);
					$this->scoreAttribute($idx, 'title', $m[5], 3);
					$this->scoreAttribute($idx, $prop, $attrArray[$prop], -3);
				}
				
				// Little Legends, The - Swamp Walk
				// deactivated because this fucks up tons auf featured artists
				#if(preg_match($rx->dStart.$rx->noMinus.$rx->glueNoWhitespace.$rx->noMinus.$rx->dEndInsens, $attrArray[$prop], $m)) {
				#	#print_r($m); die();
				#	#$this->scoreAttribute($idx, 'artist', $m[1], 3);
				#	#$this->scoreAttribute($idx, 'title', $m[2], 3);
				#}
				
				
				
			}
		}
		
	}

	private static function isVA($input) {
		switch(az09($input)) {
			case 'various':
			case 'variousartist':
			case 'variousartists':
			case 'va':
				return TRUE;
		}
		if(preg_match("/(v\.a\.|various|various\ artists|various\ artist)(?:[ .\-_]{1,4})(.*)$/i", $input)) {
			return TRUE;
		}
		return FALSE;
	}
	
	private function scoreAllTracksWithAttribute($attrName, $attrValue, $score = 1) {
		foreach($this->tracks as $idx => $bla) {
			$this->scoreAttribute($idx, $attrName, $attrValue, $score);
		}
	}



	private function scoreAttribute($idx, $attrName, $attrValue, $score = 1) {
		#cliLog($idx, 10, 'purple');
		#cliLog($attrName, 10, 'purple');
		#cliLog($attrValue, 10, 'purple');
		#cliLog($score, 10, 'purple');
		#print_r($this->r);
		#cliLog('---------', 10);
		
		$rx = new \Slimpd\RegexHelper();
		
		
		switch($attrName) {
			case 'year':
				if($rx->seemsYeary($attrValue) === FALSE) {
					return;
				}
				if($idx !== 'album') {
					// add tiny score to album in case track gets a year-score
					$this->scoreAttribute('album', $attrName, $attrValue, 0.2);
				}
				break;
			case 'catalogNr':
				if($rx->seemsCatalogy($attrValue) === TRUE) {
					$attrValue = preg_replace('/[^A-Z0-9]/', "", strtoupper($attrValue));
				} else {
					return;
				}
				break;
			case 'title':
				if($rx->seemsTitly($attrValue) === FALSE) {
					return;
				}
				break;
			case 'artist':
				if($rx->seemsArtistly($attrValue) === FALSE) {
					if(isset($this->r[$idx][$attrName][$attrValue]) === FALSE) {
						$this->r[$idx][$attrName][$attrValue] = 0;
					}
					$this->r[$idx][$attrName][$attrValue] -= $this->defaultScoreForRealTagAttrs;
					#print_r($this->r[$idx][$attrName][$attrValue]); die();
					return;
				}
				break;
			case 'number':
				$attrValue = ltrim($attrValue, '0');
				// in case we have letters make sure no combinations of different letters get scored (like 'if', 'be', ...)
				if(is_numeric($attrValue) === FALSE) {
					$letterVariations = count(array_unique(str_split($attrValue)));
					if($letterVariations > 1) {
						$score = 0;
					}
				} 
				break;
		}
		$attrValue = remU($attrValue);
		if($attrValue == '') {
			return;
		}
		
		
		
		if(isset($this->r[$idx][$attrName][$attrValue]) === FALSE) {
			$this->r[$idx][$attrName][$attrValue] = 0;
		}
		$this->r[$idx][$attrName][$attrValue] += $score;
	}


	private function getAlbumScheme($value, $idx) {
		if($value == '') {
			return 'missing';
		}
		
		
		#$result = "nomatch";
		#cliLog(__FUNCTION__ ." ".$result .": " . $value ,3 , 'red');
		#return $result;
		
		$rx = new \Slimpd\RegexHelper();
		
		// What Has Become EP-HWARE007 Vinyl
		// The Horsemen Present Revelations-HWARECD01
		// Renegade Hardware Babylon Mixed By Ink
		// Last Of A Dying Breed (HWARECD04)
		// Revelations-HWARELP01 Vinyl
		// Ink & Loxy presents: Horsementality
		// Wichi EP (WEB)
		// Get A Life (Mama Yette) WEB
		// Back 2 Basics Records (B2B12083)
		// Luce Polare EP (PLN006) Vinyl
		// Rugged Vinyl (Rugged24)
		// With The (Re-Edited) (AUX05) Vinyl
		// Crowd Rocker-(STRIKE38) Vinyl
		// NHS57_Outpatients 3 EP
		// worldwide 001 sampler WEB
		// the shake e.p. (vinyl)
		// Rotten Apple (Vinyl, 12'')
		// AWD003
		// lucky spin recordings | LSR009
		// Interracial Amour (Vinyl) 
		// cd2
		// Live at the Roxy N.Y. Dec '83
		// Hiro BW Apocalypto
		// FM4 Soundselection Vol. 9
		
		#$value = 'AWD003';
		
		$iHateRegex = array(
			// AWD003 (WEB)
			'catalog-source' => $rx->dStart.$rx->catNr.$rx->glue.$rx->source.$rx->dEnd,
			
			// AWD003 (WEB)
			'catalog' => $rx->dStart.$rx->catNr.$rx->dEnd,
			
			// What Has Become EP-HWARE007 Vinyl
			'album-catalog-source' => $rx->dStart.$rx->anything.$rx->glue.$rx->catNr.$rx->glue.$rx->source.$rx->dEndInsens,
			
			// The Horsemen Present Revelations-HWARECD01
			// Rugged Vinyl (Rugged24)
			'album-catalog' => $rx->dStart.$rx->anything.$rx->glue.$rx->catNr.$rx->dEndInsens,
			
			// the shake e.p. (vinyl)
			'album-source' => $rx->dStart.$rx->anything.$rx->glue.$rx->source.$rx->dEndInsens,
		);
		foreach($iHateRegex as $result => $pattern) {
			if(preg_match($pattern, $value, $m)) {
				switch($result) {
					// make recommendations for each track
					case 'catalog':
						#print_r($m); die($result);
						$this->recommend('album',array('catalogNr' => remU($value)));
						$this->recommend($idx,array('catalogNr' => remU($value)));
						break;
					case 'catalog-source':
						#print_r($m); die($result);
						$this->recommend('album',
							array(
								'catalogNr' => remU($m[3]),
								'source' => remU($m[7])
						));
						$this->recommend($idx,
							array(
								'catalogNr' => remU($m[3]),
								'source' => remU($m[7])
						));
						break;
					case 'album-catalog-source':
						#print_r($m); die($result);
						$this->recommend('album',
							array(
								'title' => remU($m[1]),
								'catalogNr' => remU($m[4]),
								'source' => remU($m[8])
						));
						$this->recommend($idx,
							array(
								'album' => remU($m[1]),
								'catalogNr' => remU($m[4]),
								'source' => remU($m[8])
						));
						break;
					case 'album-catalog':
						#print_r($m); die($result);
						$this->recommend('album',
							array(
								'title' => remU($m[1]),
								'catalogNr' => remU($m[4])
						));
						$this->recommend($idx,
							array(
								'album' => remU($m[1]),
								'catalogNr' => remU($m[4])
						));
						break;
					case 'album-source':
						#print_r($m); die($result);
						$this->recommend('album',
							array(
								'title' => remU($m[1]),
								'source' => remU($m[4])
						));
						$this->recommend($idx,
							array(
								'album' => remU($m[1]),
								'source' => remU($m[4])
						));
						break;
				}
				#cliLog(__FUNCTION__ ." ".$result .": " . $value ,3 , 'green');
				return $result;
			}
		}
		$this->recommend('album', array('title' => remU($value)));
		$this->recommend($idx, array('album' => remU($value)));
		
		$result = "nomatch";
		#cliLog(__FUNCTION__ ." ".$result .": " . $value ,3 , 'red');
		return $result;
	}


	private function getFilenameScheme($value, $idx) {
		#$value = "B2-Aaron_Dilloway-Untitled-sour.mp3";
		
		if($value == '') {
			return 'missing';
		}
		$rx = new \Slimpd\RegexHelper();
		
		// maybe we have a year in filename -> add little score
		if(preg_match_all("/".$rx->mayBracket.$rx->year.$rx->mayBracket."/", $value, $m)) {
			foreach($m as $i) {
				foreach($i as $x) {
					$this->scoreAttribute($idx, 'year', $x, 0.5);
				}
			}
		}
		// maybe we have a catNr in filename  -> add little score
		if(preg_match_all("/".$rx->catNr."/", $value, $m)) {
			foreach($m as $i) {
				foreach($i as $x) {
					$this->scoreAttribute($idx, 'catalogNr', $x, 0.5);
				}
			}
		}

		
		
		$iHateRegex = array(
			// 01-Aaron_Dilloway-Untitled.mp3
			'classic' => $rx->dStart.$rx->num.$rx->glue.$rx->noMinus."-".$rx->noMinus.$rx->ext.$rx->dEnd,
			// A1-Aaron_Dilloway-Untitled.mp3
			'classic-vinyl' => $rx->dStart.$rx->vinyl.$rx->glue.$rx->noMinus."-".$rx->noMinus.$rx->ext.$rx->dEnd,
			// 112-Aaron_Dilloway-Untitled.mp3
			'classicscene' => $rx->dStart.$rx->num.$rx->glue.$rx->noMinus."-".$rx->noMinus.$rx->scene.$rx->ext.$rx->dEnd,
			// B2-Aaron_Dilloway-Untitled-sour.mp3
			'classicscene-vinyl' => $rx->dStart.$rx->vinyl.$rx->glue.$rx->noMinus."-".$rx->noMinus.$rx->scene.$rx->ext.$rx->dEnd,
			
			// V.A. Brazilified - 05 - Mr Gone - Mosquito Coast
			'album-number-artist-title' => $rx->dStart.$rx->noMinus.$rx->glue.$rx->num.$rx->glue.$rx->noMinus.$rx->glue.$rx->noMinus.$rx->ext.$rx->dEnd,
			
			// V.A. Brazilified - A1 - Mr Gone - Mosquito Coast
			'album-vinyl-artist-title' => $rx->dStart.$rx->noMinus.$rx->glue.$rx->vinyl.$rx->glue.$rx->noMinus.$rx->glue.$rx->noMinus.$rx->ext.$rx->dEnd,
			
			
			// 05-Voodoo_Man.mp3
			'noartist' => $rx->dStart.$rx->num.$rx->glue.$rx->noMinus.$rx->ext.$rx->dEnd,
			// B2-Voodoo_Man_(Last_Break_Mix).mp3
			'noartist-vinyl' => $rx->dStart.$rx->vinyl.$rx->glue.$rx->noMinus.$rx->ext.$rx->dEnd,
			
			// Aaron_Dilloway_-_Voodoo_Man_(Last_Break_Mix).mp3
			'nonumber' => $rx->dStart.$rx->noMinus."-".$rx->noMinus.$rx->ext.$rx->dEnd,
			
			// Voodoo_Man.mp3
			'nonumber-noartist' => $rx->dStart.$rx->noMinus.$rx->ext.$rx->dEnd,
			
			'anything' => $rx->dStart.$rx->anything.$rx->ext.$rx->dEnd,
		);
		foreach($iHateRegex as $result => $pattern) {
			#cliLog($pattern);
			if(preg_match($pattern, $value, $m)) {
				if($result !== 'nonumber' && $result !== 'nonumber-noartist') {
					if(is_numeric($m[1])) {
						$this->extractedTrackNumbers[$idx] = intval($m[1]);
					} else {
						// vinyl schemed tracknumer
						$this->extractedTrackNumbers[$idx] = strtoupper($m[1]);
					}
				}
			
				switch($result) {
					// make recommendations for expected track-attributes
					case 'classic':
					case 'classicscene' :
					case 'classic-vinyl':
					case 'classicscene-vinyl':
						$this->recommend($idx, array(
							'number' => remU($m[1]),
							'artist' => remU($m[2]),
							'title' => remU($m[3])
						));
						break;
					case 'noartist':
					case 'noartist-vinyl':
						$this->recommend($idx, array(
							'number' => remU($m[1]),
							'title' => remU($m[2])
						));
						break;
					case 'nonumber':
						$this->recommend($idx, array(
							'artist' => remU($m[1]),
							'title' => remU($m[2])
						));
						break;
					case 'album-number-artist-title':
					case 'album-vinyl-artist-title':
						$this->recommend($idx, array(
							'album' => remU($m[1]),
							'number' => remU($m[2]),
							'artist' => remU($m[3]),
							'title' => remU($m[4])
						));
						$this->recommend('album', array(
							'title' => remU($m[1])
						));
					case 'nonumber-noartist':
					case 'anything':
						$this->recommend($idx, array(
							'title' => remU($m[1])
						));
						break;
				}
				if(stripos($result, 'vinyl') !== FALSE) {
					$this->recommend($idx, array(
						'source' => 'Vinyl'
					));
				}		
					
				cliLog(__FUNCTION__ ." ".$result .": " . $value ,6 , 'green');
				return $result; // 01-Aaron_Dilloway-Untitled.mp3
			}
		}
		$result = "nomatch";
		cliLog(__FUNCTION__ ." ".$result .": " . $value ,6 , 'red');
		return $result;
	}

	private function getFilenameCase($value) {
		// exclude the file-extension
		$value = preg_replace('/\\.[^.\\s]{3,4}$/', '', $value);
		
		if(strtolower($value) === $value) { return 'lower'; }
		if(strtoupper($value) === $value) { return 'upper'; }
		return 'mixed';
	}
	
	private function getNumberScheme($value, $idx=NULL) {
		$value = str_replace(array("of", " ", ".", ","), "/", $value);
		if($value == '') {
			return 'missing';
		}
		if(intval($value) == strval($value) && is_numeric($value) === TRUE) {
			$this->extractedTrackNumbers[$idx] = $value;
			$this->recommend($idx, array('number' => $value));
			return 'simple'; // 1, 2, 3
		}
		
		if(ltrim($value,'0') != strval($value)) {
			$this->extractedTrackNumbers[$idx] = intval($value);
			$this->recommend($idx, array('number' => intval($value)));
			return 'leadingzero';	// 01, 02
		}
		if(preg_match("/^(\d*)\/(\d*)$/", $value, $m)) {
			$this->extractedTrackNumbers[$idx] = intval($m[1]);
			$this->extractedTotalTracks[$idx] = intval($m[2]);
			$this->recommend($idx, array('number' => intval($m[1])));
			$this->recommend('album', array('totalTracks' => intval($m[2])));
			return 'slashsplit'; // 01/12 , 2/12
		}
		if(preg_match("/^([a-zA-Z]{1,2})(?:[\/-]{1})(\d*)$/", $value)) {
			if($idx !== NULL) { 
				$this->extractedTrackNumbers[$idx] = $value;
				$this->recommend($idx, array('number' => $value, 'source' => 'Vinyl'));
				$this->recommend('album', array('source' => 'Vinyl'));
			}
			return 'vinyl';	// AA1, B2, C34, A-1, A/4
		}
		cliLog(__FUNCTION__ ."(" . $value . ") unknown",6 , 'red');
		return 'unknown';
	}
	
	
	/**
	 * in case we find ranges without gaps add aditional score
	 * 
	 */
	private function addChronologyScore() {
		if(count($this->extractedTrackNumbers) === 0) {
			return;
		}
		$orderedByRelevance = uniqueArrayOrderedByRelevance($this->extractedTrackNumbers);
		if(count($orderedByRelevance) < 2) {
			return;
		}
		$sorted = $this->extractedTrackNumbers;
		sort($sorted);
		$joined = join("",$sorted);
		if(preg_match("/^([0-9]+)$/", $joined) === 0) {
			
			# TODO: does it make sense to guess a valid range of A1, AA1,...
			# some labels does not use A,B,... as letteHashrs
			# for now ignore any range but score in case really all extracted tracknumbers are vinyl-schemed
			$isVinylPattern = TRUE;
			foreach($this->extractedTrackNumbers as $i) {
				if($this->getNumberScheme($i) !== 'vinyl') {
					$isVinylPattern = FALSE;
				}
			}
			
			if($isVinylPattern === TRUE) {
				$this->handleAsAlbumScore += 3*count($this->extractedTrackNumbers);
			}
			return;
		}

		$rangeNess = $this->extractNumericRangeness($sorted);
		if(count($rangeNess) === 1) {
			$this->handleAsAlbumScore += 5;
			return;
		}
		
		// multiple disc releases often has more ranges
		// 101 - 1XX, 201 - 20X
		$discRange = TRUE;
		foreach($rangeNess as $range) {
			if(preg_match("/^(\d{1})01-/", $range, $m) === 0) {
				$discRange = FALSE;
			}
		}
		if($discRange === TRUE) {
			$this->handleAsAlbumScore += 5*count($this->extractedTrackNumbers);
			return;
		}
		
		return;
	}
	
	
	
	
	/**
	 * TODO: remove this strange syntax "goto" of copy/pasted method
	 * 
	 */
	private function extractNumericRangeness($input) {
		
		//last value is dropped so add something useless to be dropped
		array_push($input, null);
		$rangeArray = array();
		
		array_walk($input, function($val) use (&$rangeArray){
		    static $oldVal, $rangeStart;
		
		    if (is_null($rangeStart))
		        goto init;
		
		    if ($oldVal+1 == $val) {
		        $oldVal = $val;
		        return;
		    }
		
		    if ($oldVal == $rangeStart) {
		        array_push($rangeArray, $rangeStart);
		        goto init;
		    }
		
		    array_push($rangeArray, $rangeStart . '-' . $oldVal);
		
		    init: {
		        $rangeStart = $val;
		        $oldVal = $val;
		    }
		});
		
		return $rangeArray;
	}
	
	
	// setter
	public function setRelativeDirectoryPathHash($value) {
		$this->relativeDirectoryPathHash = $value;
	}
	
	public function setRelativeDirectoryPath($value) {
		$this->relativeDirectoryPath = $value;
	}
	
	public function setDirectoryMtime($value) {
		$this->directoryMtime = $value;
	}
	
	
	public function addTrack(array $rawTagDataArray) {
		$this->tracks[] = $rawTagDataArray;
	}
	
	// getter
	public function getRelativeDirectoryPathHash() {
		return $this->relativeDirectoryPathHash;
	}
	
	public function getRelativeDirectoryPath() {
		return $this->relativeDirectoryPath;
	}
	
	public function getDirectoryMtime() {
		return $this->directoryMtime;
	}
}
	