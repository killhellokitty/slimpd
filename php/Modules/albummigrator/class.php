<?php
namespace Slimpd\Modules;

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
	protected $relDirPathHash;
	protected $relDirPath;
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

	protected $mostRecentAdded = 0;

	// recommendations
	protected $r;

	// attribute with highest score from recommendations
	protected $mostScored = array();
	protected $scoreForRealTags = 5;

	
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

		cliLog("handleAsAlbum " . (($this->handleAsAlbum)?"yes":"no") . " (SCORE: ".$this->handleAsAlbumScore.")", 3, 'purple');

		
		#if($this->tracks[0]['relPath'] == 'newroot/crse002cd--Calibre-Musique_Concrete-2CD-CRSE002CD-2001-sour/101-calibre-deep_everytime.mp3') {
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

		$album = new \Slimpd\Models\Album();

		$album->setArtistId(join(",", \Slimpd\Models\Artist::getIdsByString($albumArtists)));
		$album->setGenreId(join(",", \Slimpd\Models\Genre::getIdsByString($mergedFromTracks['genre'])));
		#$album->setLabelId(join(",", Label::getIdsByString($mergedFromTracks['label'])));
		$album->setCatalogNr($this->mostScored['album']['catalogNr']);

		$album->setRelPath($this->getRelDirPath());
		$album->setRelPathHash($this->getRelDirPathHash());
		$album->setFilemtime($this->getDirectoryMtime());
		$album->setAdded($this->mostRecentAdded);

		$album->setTitle($this->mostScored['album']['title']);
		$album->setYear($this->mostScored['album']['year']);

		$album->setIsJumble(($this->handleAsAlbum === 1) ? 0:1);

		$album->setTrackCount(count($this->tracks));

		#print_r($album); die();
		$album->update();

		$albumId = $album->getId();

		// add the whole bunch of valid and invalid attributes to albumindex table
		$this->updateAlbumIndex($albumId);

		

		foreach($this->tracks as $idx => $rawTagData) {
			$track = $this->migrateNonGuessableData($rawTagData);
			$track->setArtistId($this->mostScored[$idx]['artist']); // currently the string insted of an artistId
			$track->setTitle($this->mostScored[$idx]['title']);
			$track->setFeaturedArtistsAndRemixers();
				# setFeaturedArtistsAndRemixers() is processing:
				# $t->setArtistId();
				# $t->setFeaturingId();
				# $t->setRemixerId();

			$track->setGenreId(join(",", \Slimpd\Models\Genre::getIdsByString($this->getMostScored($idx, 'genre'))));
			$track->setLabelId(join(",", \Slimpd\Models\Label::getIdsByString($this->getMostScored($idx, 'label'))));
			$track->setCatalogNr($this->mostScored[$idx]['catalogNr']);
			$track->setDisc($this->mostScored[$idx]['disc']);
			$track->setTrackNumber($this->mostScored[$idx]['trackNumber']);
			$track->setComment($this->mostScored[$idx]['comment']);
			$track->setYear($this->mostScored[$idx]['year']);
			$track->setAlbumId($albumId);

			// make sure to use identical ids in table:rawtagdata and table:track
			\Slimpd\Models\Track::ensureRecordIdExists($track->getId());
			$track->update();

			// make sure extracted images will be referenced to an album
			\Slimpd\Models\Bitmap::addAlbumIdToTrackId($track->getId(), $albumId);#
			
			// add the whole bunch of valid and indvalid attributes to trackindex table
			$this->updateTrackIndex($track->getId(), $idx);
		}

		unset($this->r['album']);

		if($this->handleAsAlbum === TRUE) {
			// try to guess if all tracks of this album has obviously invalid fixable attributes
		} 

		return;
		#print_r($this->r); die();
	}

	private function updateTrackIndex($trackId, $idx) {
		$indexChunks = $this->tracks[$idx]['relPath'] . " ";

		if(isset($this->r[$idx]) === TRUE) {
			foreach($this->r[$idx] as $scoreCombo) {
				$indexChunks .= join(" ", array_keys($scoreCombo)) . " ";
			}
		}
		if(isset($this->r['album']) === TRUE) {
			foreach($this->r['album'] as $scoreCombo) {
				$indexChunks .= join(" ", array_keys($scoreCombo)) . " ";
			}
		}
		$indexChunks .= join(" ", $this->mostScored[$idx]) . " ";
		$indexChunks .= join(" ", $this->mostScored['album']) . " ";
		$indexChunks .= str_replace(
			array('/', '_', '-', '.'),
			' ',
			$this->tracks[$idx]['relPath']
		);
		// make sure to use identical ids in table:trackindex and table:track
		\Slimpd\Models\Trackindex::ensureRecordIdExists($trackId);
		$trackIndex = new \Slimpd\Models\Trackindex();
		$trackIndex->setId($trackId);
		$trackIndex->setArtist($this->mostScored[$idx]['artist']);
		$trackIndex->setTitle($this->mostScored[$idx]['title']);
		$trackIndex->setAllchunks($indexChunks);
		$trackIndex->update();
	}


	private function updateAlbumIndex($albumId) {
		$indexChunks = $this->tracks[0]['relDirPath'] . " ";
		if(isset($this->r['album']) === TRUE) {
			foreach($this->r['album'] as $scoreCombo) {
				$indexChunks .= join(" ", array_keys($scoreCombo)) . " ";
			}
		}
		$indexChunks .= join(" ", $this->mostScored['album']) . " ";
		$indexChunks .= str_replace(
			array('/', '_', '-', '.'),
			' ',
			$this->tracks[0]['relDirPath']
		);
		// make sure to use identical ids in table:trackindex and table:track
		\Slimpd\Models\Albumindex::ensureRecordIdExists($albumId);
		$albumIndex = new \Slimpd\Models\Albumindex();
		$albumIndex->setId($albumId);
		$albumIndex->setArtist($this->mostScored['album']['artist']);
		$albumIndex->setTitle($this->mostScored['album']['title']);
		$albumIndex->setAllchunks($indexChunks);
		$albumIndex->update();
	}

	/**
	 * no guessing - if value seems reasonable or not - required
	 */
	public function migrateNonGuessableData($rawArray) {
		$track = new \Slimpd\Models\Track();
		$track->setId($rawArray['id']);
		$track->setRelPath($rawArray['relPath']);
		$track->setRelPathHash($rawArray['relPathHash']);
		$track->setRelDirPathHash($rawArray['relDirPathHash']);
		$track->setFingerprint($rawArray['fingerprint']);
		$track->setMimeType($rawArray['mimeType']);
		$track->setFilesize($rawArray['filesize']);
		$track->setFilemtime($rawArray['filemtime']);
		$track->setMiliseconds(round($rawArray['miliseconds']*1000));
		$track->setAudioDataformat($rawArray['audioDataformat']);
		$track->setAudioComprRatio($rawArray['audioComprRatio']);
		$track->setAudioEncoder(($rawArray['audioEncoder']) ? $rawArray['audioEncoder'] : 'Unknown encoder');
		if ($rawArray['audioLossless']) {
			$track->setAudioLossless($rawArray['audioLossless']);
			$track->setAudioProfile('Lossless compression');
			if ($rawArray['audioComprRatio'] == 1) {
				$track->setAudioProfile('Lossless');
			}
		}
		$track->setAudioBitrate(round($rawArray['audioBitrate'])); // integer in database
		if(!$track->getAudioProfile()) {
			$track->setAudioProfile($rawArray['audioBitrateMode'] . " " . round($track->getAudioBitrate()/ 1000, 1) . " kbps");
		}
		$track->setAudioBitsPerSample(($rawArray['audioBitsPerSample'] ? $rawArray['audioBitsPerSample'] : 16));
		$track->setAudioSampleRate(($rawArray['audioSampleRate'] ? $rawArray['audioSampleRate'] : 44100));
		$track->setAudioChannels(($rawArray['audioChannels'] ? $rawArray['audioChannels'] : 2));

		$track->setVideoDataformat($rawArray['videoDataformat']);
		$track->setVideoCodec($rawArray['videoCodec']);
		$track->setVideoResolutionX($rawArray['videoResolutionX']);
		$track->setVideoResolutionY($rawArray['videoResolutionY']);
		$track->setVideoFramerate($rawArray['videoFramerate']);

		$track->setImportStatus($rawArray['importStatus']);
		$track->setLastScan($rawArray['lastScan']);

		$track->setError($rawArray['error']);
		$track->setDr($rawArray['dynamicRange']);
		return $track;
	}

	private function postProcessRecommendations() {
		$attrNames = array(
			'artist',
			'title',
			'year',
			'catalogNr',
			'label',
			'source',
			'trackNumber',
			'disc',
			'genre',
			'comment',
			'album',
		);
		foreach($attrNames as $attrName) {
			foreach(array_keys($this->tracks) as $idx) {
				$this->mostScored[$idx][$attrName] = $this->getMostScored($idx, $attrName);
			}
			$this->mostScored['album'][$attrName] = $this->getMostScored('album', $attrName);

		}
		$rgx = new \Slimpd\RegexHelper();
		
		// last fixes :)                                   hopefully...
		foreach($this->mostScored as $idx => $item) {
			if($idx === 'album') {
				continue;
			}
			#cliLog("/^". preg_quote($item['artist']).$rgx->glue ."/i");
			// remove artist from title in case title starts with artists
			// A: Little Legends, The
			// T: Little Legends, The - Swamp Walk
			if($item['artist'] !== '' && preg_match("/^". preg_quote($item['artist'], "/").$rgx->glue ."(.{5,})/i", $item['title'], $matches)) {
				#print_r($item); #die();
				$this->mostScored[$idx]['title'] = remU($matches[1]);
			}

			// in case album:title == artist take 2nd scored
			//if($this->tracks[$idx]['artist'] == $item['album']
			//&& $this->tracks[$idx]['artist'] !== ''
			//&& preg_match("/^".$rgx->noMinus.$rgx->glue .preg_quote($item['title'], "/")."$/i", $this->tracks[$idx]['title'], $matches)) {
			//	print_r($matches); #die();
			//	// match -> neuer artist
			//	#print_r($this->r[$idx]); die();
			//	$this->mostScored[$idx]['artist'] = trim($matches[1]); 
			//}
		}
	}

	

	private function setHandleAsAlbum() {


		// collect specific data for comparison
		foreach($this->tracks as $idx => $track) {

			$this->artists[$idx] = $track['artist'];
			$this->albums[$idx] = $track['album'];
			$this->albumArtists[$idx] = $track['albumArtist'];
			$this->genres[$idx] = $track['genre'];
			$this->comments[$idx] = $track['comment'];
			$this->years[$idx] = $track['year'];
			$this->labels[$idx] = $track['publisher'];
			$this->catalogNumbers[$idx] = $track['catalogNr'];
			$this->discogsReleaseIds[$idx] = $track['textDiscogsReleaseId'];
			$this->sources[$idx] = $track['textSource'];
			$this->urls[$idx] = $track['textUrlUser'];

			$this->mimeTypes[$idx] = $track['mimeType'];
			$this->audioBitrates[$idx] = $track['audioBitrate'];
			$this->audioBitrateModes[$idx] = $track['audioBitrateMode'];
			$this->audioSampleRates[$idx] = $track['audioSampleRate'];
			$this->audioDataformats[$idx] = $track['audioDataformat'];
			$this->audioEncoders[$idx] = $track['audioEncoder'];
			$this->audioLosslesss[$idx] = $track['audioLossless'];

			$this->totalTrackss[$idx] = $track['totalTracks'];

			$this->filenameCases[$idx] = $this->getFilenameCase( basename($track['relPath']) );

			$this->filenameSchemes[$idx] = $this->getFilenameScheme( basename($track['relPath']), $idx);
			$this->artistSchemes[$idx] = $this->getArtistOrTitleScheme($track['artist'], $idx, 'artist'); // we can use the same
			$this->titleSchemes[$idx] = $this->getArtistOrTitleScheme($track['title'], $idx, 'title');
			$this->albumSchemes[$idx] = $this->getAlbumScheme($track['album'], $idx);
			$this->numberSchemes[$idx] = $this->getNumberScheme($track['trackNumber'], $idx);

			// album gets the most recent timestamp of all tracks for attribute "added"
			$this->mostRecentAdded = ($track['added'] > $this->mostRecentAdded) ? $track['added'] : $this->mostRecentAdded;

			// add score for real unmodified attributes
			$this->scoreAttribute($idx, 'artist',    $track['artist'], $this->scoreForRealTags);
			$this->scoreAttribute($idx, 'artist',    $track['albumArtist'], $this->scoreForRealTags);
			$this->scoreAttribute($idx, 'title',     $track['title'], $this->scoreForRealTags);
			$this->scoreAttribute($idx, 'genre',     $track['genre'], $this->scoreForRealTags);
			$this->scoreAttribute($idx, 'comment',   $track['comment'], $this->scoreForRealTags);
			$this->scoreAttribute($idx, 'year',      $track['year'], $this->scoreForRealTags);
			$this->scoreAttribute($idx, 'label',     $track['publisher'], $this->scoreForRealTags);
			$this->scoreAttribute($idx, 'catalogNr', $track['catalogNr'], $this->scoreForRealTags);
			$this->scoreAttribute($idx, 'discogsId', $track['textDiscogsReleaseId'], $this->scoreForRealTags);
			$this->scoreAttribute($idx, 'source',    $track['textSource'], $this->scoreForRealTags);
			$this->scoreAttribute($idx, 'urlUser',   $track['textUrlUser'], $this->scoreForRealTags);
			$this->scoreAttribute('album', 'title',  $track['album'], $this->scoreForRealTags);
			$this->scoreAttribute('album', 'artist', $track['albumArtist'], $this->scoreForRealTags);
			$this->scoreAttribute('album', 'artist', $track['artist'], $this->scoreForRealTags);
		}
		$this->guessAttributesByDirectoryName($track['relDirPath']);

		$this->scoreLabelByLabelDirectory($track['relDirPath']);

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

		#$decisionBoundry = 11;
		$decisionBoundry = 20;

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
		$this->handleAsAlbum = ($this->handleAsAlbumScore>$decisionBoundry) ? 1 : 0;
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
		$rgx = new \Slimpd\RegexHelper();
		$iHateRegex = array(
			// 01 - Super Tracktitle
			'prefixed-number' => $rgx->dStart.$rgx->mayBracket.$rgx->num.$rgx->mayBracket.$rgx->glue.$rgx->noMinus.$rgx->dEndInsens,
			// B2. Super Tracktitle
			'prefixed-vinyl' => $rgx->dStart.$rgx->mayBracket.$rgx->vinyl.$rgx->mayBracket.$rgx->glue.$rgx->noMinus.$rgx->dEndInsens,

			// Artist - Super Tracktitle
			'artist-title' => $rgx->dStart.$rgx->noMinus."-".$rgx->noMinus.$rgx->dEnd,
			// 01 - Artist - Super Tracktitle
			'prefixed-number-artist-title' => $rgx->dStart.$rgx->num.$rgx->glue.$rgx->noMinus."-".$rgx->noMinus.$rgx->dEndInsens,
			// B2.-Artist - Super Tracktitle
			'prefixed-vinyl-artist-title' => $rgx->dStart.$rgx->vinyl.$rgx->glue.$rgx->noMinus."-".$rgx->noMinus.$rgx->dEndInsens,

			// Super Tracktitle
			'classic' => $rgx->dStart.$rgx->noMinus.$rgx->dEndInsens,
		);
		foreach($iHateRegex as $result => $pattern) {
			if(preg_match($pattern, $value, $matches)) {
				switch($result) {
					// make recommendations for each track
					case 'classic':
						$this->recommend($idx, array($artistOrTitle => $matches[1]));
						break;
					case 'prefixed-number':
					case 'prefixed-vinyl':
						#print_r($matches); die($result);
						$this->recommend($idx, array(
							'trackNumber' => $matches[2],
							$artistOrTitle => $matches[4]
						));
						#$this->scoreAttribute($idx, $artistOrTitle, $value, ($this->scoreForRealTags*(-1)));
						break;
					case 'artist-title':
						$this->recommend($idx, array(
							'artist' => $matches[1],
							'title' => $matches[2]
						));
						break;
					case 'prefixed-number-artist-title':
					case 'prefixed-vinyl-artist-title':
						$this->recommend($idx, array(
							'trackNumber' => $matches[1],
							'artist' => $matches[2],
							'title' => $matches[3]
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

		$rgx = new \Slimpd\RegexHelper();
		#$value = "Tony_Tuff-Tony_Tuff_1980";
		#$value = "Tony_Tuff-Tony_Tuff_(ESO1980";
		#$value = "Tony_Tuff-Tony_Tuff"; 
		$iHateRegex = array(
			// VA-Congo_Sevens_Number_1
			'artist-title-year' => $rgx->dStart.$rgx->noMinus.$rgx->glue.$rgx->noMinus.$rgx->glue.$rgx->mayBracket.$rgx->year.$rgx->mayBracket.$rgx->dEnd,

			// VA-Congo_Sevens_Number_1
			'artist-title' => $rgx->dStart.$rgx->noMinus.$rgx->glue.$rgx->noMinus.$rgx->dEnd,

		);
		foreach($iHateRegex as $result => $pattern) {
			#cliLog($pattern);
			#cliLog($value);
			if(preg_match($pattern, $value, $matches)) {
				switch($result) {
					case 'artist-title-year':
						#print_r($matches); die($result);
						$this->recommend('album', array(
							'artist' => remU($matches[1]),
							'title' => remU($matches[2]),
							'year' => remU($matches[3]),
						));
						break;
					case 'artist-title':
						#print_r($matches); die($result);
						$this->recommend('album', array(
							'artist' => remU($matches[1]),
							'title' => remU($matches[2])
						));
						break;
				}
				if(stripos($result, 'vinyl') !== FALSE) {
					$this->recommend('album', array('source' => 'Vinyl'));
					foreach(array_keys($this->tracks) as $idx) {
						$this->recommend($idx, array('source' => 'Vinyl'));
					}
				}
			}
		}

		$this->recommend('album', array('title' => remU($value)));

		if(preg_match_all("/".$rgx->mayBracket.$rgx->year.$rgx->mayBracket."/", $value, $matches)) {
			foreach($matches as $matches2) {
				foreach($matches2 as $match) {
					$this->scoreAttribute('album', 'year', $match);
					$this->scoreAllTracksWithAttribute('year', $match);
				}
			}
		}
		if(preg_match_all("/".$rgx->catNr."/", $value, $matches)) {
			foreach($matches as $matches2) {
				foreach($matches2 as $match) {
					$this->scoreAttribute('album', 'catalogNr', $match);
					$this->scoreAllTracksWithAttribute('catalogNr', $match);
				}
			}
		}
		// for my personal collection - pretty sure this is not very common
		if(preg_match("/^([a-z0-9_]{1,15})\-\-/", $value, $matches)) {
			$this->scoreAttribute('album', 'catalogNr', $matches[1], 2);
			$this->scoreAllTracksWithAttribute('catalogNr', $matches[1], 2);
		}
	}

	private function scoreLabelByLabelDirectory($albumPath) {
		cliLog("--- add LABEL based on directory ---", 8);
		cliLog("  album directory: " . $albumPath, 8);
		$app = \Slim\Slim::getInstance();

		// check config
		if(isset($app->config['label-parent-directories']) === FALSE) {
			cliLog("  aborting because no label directories configured",8);
			return;
		}

		foreach($app->config['label-parent-directories'] as $labelDir) {
			$labelDir = rtrim($labelDir, DS) . DS; // append trailingSlash
			cliLog("  configured label dir: " . $labelDir, 10);
			if(stripos($albumPath, $labelDir) !== 0) {
				cliLog("  no match: " . $labelDir, 8);
				continue;
			}
			// use directory name as label name
			$newLabelString = basename(dirname($albumPath));

			// do some cleanup
			$newLabelString = ucwords(remU($newLabelString));
			cliLog("  match: " . $newLabelString, 8);
			$this->scoreAttribute('album', 'label', $newLabelString, 5);
			foreach(array_keys($this->tracks) as $idx) {
				$this->scoreAttribute($idx, 'label', $newLabelString, 5);
			}
			return;
		}
		return;
	}

	private function recommend($idx, $attrArray, $score = 1) {
		$rgx = new \Slimpd\RegexHelper();

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
				if(preg_match("/^".$rgx->anything."([ .\/\-_]{".$len."})".$rgx->anything."$/", $attrArray['title'],$matches)) {
					$this->scoreAttribute($idx, 'artist', $matches[1], 3);
					$this->scoreAttribute($idx, 'title', $matches[3], 3);
					break;
				}
			}
		}

		
		// remove various artists in case we find it in albumtitle
		if($idx == 'album' && isset($attrArray['title'])) {
			if(preg_match("/".$rgx->various.$rgx->glue. $rgx->anything.$rgx->dEndInsens, $attrArray['title'], $matches)) {
				#print_r($matches);
				$this->scoreAttribute('album', 'title', $matches[2], 3);
				$this->scoreAttribute('album', 'title', $attrArray['title'], -2);
			}
		}

		// one more sheme parsing of artist or title attribute
		if(isset($attrArray['title']) === FALSE && isset($attrArray['artist']) === FALSE) {
			return;
		}

		#TODO : da weitermachen
		foreach(array("artist", "title") as $prop) {
			if(isset($attrArray[$prop]) === FALSE) {
				continue;
			}
			#cliLog($attrArray[$prop]);
			#cliLog($rgx->dStart.$rgx->num.$rgx->glue.$rgx->noMinus.$rgx->glue.$rgx->noMinus.$rgx->dEndInsens);
			if(preg_match($rgx->dStart.$rgx->num.$rgx->glue.$rgx->noMinus.$rgx->glue.$rgx->noMinus.$rgx->dEndInsens, $attrArray[$prop], $matches)) {
				$this->scoreAttribute($idx, 'trackNumber', $matches[1], 3);
				$this->scoreAttribute($idx, 'artist', $matches[2], 3);
				$this->scoreAttribute($idx, 'title', $matches[3], 3);
				$this->scoreAttribute($idx, $prop, $attrArray[$prop], -2);
			}
			if(preg_match($rgx->dStart.$rgx->vinyl.$rgx->glue.$rgx->noMinus.$rgx->glue.$rgx->noMinus.$rgx->dEndInsens, $attrArray[$prop], $matches)) {
				$this->scoreAttribute($idx, 'trackNumber', $matches[1], 3);
				$this->scoreAttribute($idx, 'artist', $matches[2], 3);
				$this->scoreAttribute($idx, 'title', $matches[3], 3);
				$this->scoreAttribute('album', 'source', 'Vinyl', 3);
				$this->scoreAttribute($idx, $prop, $attrArray[$prop], -2);
			}

			// Ahmad Jamal with Voices-1967-Cry Young-04-Who Needs Manhattan
			if(preg_match($rgx->dStart.$rgx->noMinus.$rgx->glue.$rgx->year.$rgx->glue.$rgx->noMinus.$rgx->glue.$rgx->num.$rgx->glue.$rgx->noMinus.$rgx->dEndInsens, $attrArray[$prop], $matches)) {
				#print_r($matches); die();
				$this->scoreAttribute($idx, 'artist', $matches[1], 3);
				$this->scoreAttribute($idx, 'year', $matches[2], 3);
				$this->scoreAttribute('album', 'year', $matches[2], 3);
				$this->scoreAttribute('album', 'title', $matches[3], 3);
				$this->scoreAttribute($idx, 'trackNumber', $matches[4], 3);
				$this->scoreAttribute($idx, 'title', $matches[5], 3);
				$this->scoreAttribute($idx, $prop, $attrArray[$prop], -3);
			}

			// Little Legends, The - Swamp Walk
			// deactivated because this fucks up tons auf featured artists
			#if(preg_match($rgx->dStart.$rgx->noMinus.$rgx->glueNoWhitespace.$rgx->noMinus.$rgx->dEndInsens, $attrArray[$prop], $matches)) {
			#	#print_r($matches); die();
			#	#$this->scoreAttribute($idx, 'artist', $matches[1], 3);
			#	#$this->scoreAttribute($idx, 'title', $matches[2], 3);
			#}
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
		foreach(array_keys($this->tracks) as $idx) {
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

		$rgx = new \Slimpd\RegexHelper();

		switch($attrName) {
			case 'year':
				if($rgx->seemsYeary($attrValue) === FALSE) {
					return;
				}
				if($idx !== 'album') {
					// add tiny score to album in case track gets a year-score
					$this->scoreAttribute('album', $attrName, $attrValue, 0.2);
				}
				break;
			case 'catalogNr':
				if($rgx->seemsCatalogy($attrValue) !== TRUE) {
					return;
				}
				$attrValue = preg_replace('/[^A-Z0-9]/', "", strtoupper($attrValue));
				break;
			case 'title':
				if($rgx->seemsTitly($attrValue) === FALSE) {
					return;
				}
				break;
			case 'artist':
				if($rgx->seemsArtistly($attrValue) === FALSE) {
					if(isset($this->r[$idx][$attrName][$attrValue]) === FALSE) {
						$this->r[$idx][$attrName][$attrValue] = 0;
					}
					$this->r[$idx][$attrName][$attrValue] -= $this->scoreForRealTags;
					#print_r($this->r[$idx][$attrName][$attrValue]); die();
					return;
				}
				break;
			case 'trackNumber':
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

		$rgx = new \Slimpd\RegexHelper();

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
			'catalog-source' => $rgx->dStart.$rgx->catNr.$rgx->glue.$rgx->source.$rgx->dEnd,

			// AWD003 (WEB)
			'catalog' => $rgx->dStart.$rgx->catNr.$rgx->dEnd,

			// What Has Become EP-HWARE007 Vinyl
			'album-catalog-source' => $rgx->dStart.$rgx->anything.$rgx->glue.$rgx->catNr.$rgx->glue.$rgx->source.$rgx->dEndInsens,

			// The Horsemen Present Revelations-HWARECD01
			// Rugged Vinyl (Rugged24)
			'album-catalog' => $rgx->dStart.$rgx->anything.$rgx->glue.$rgx->catNr.$rgx->dEndInsens,

			// the shake e.p. (vinyl)
			'album-source' => $rgx->dStart.$rgx->anything.$rgx->glue.$rgx->source.$rgx->dEndInsens,
		);
		foreach($iHateRegex as $result => $pattern) {
			if(preg_match($pattern, $value, $matches)) {
				switch($result) {
					// make recommendations for each track
					case 'catalog':
						#print_r($matches); die($result);
						$this->recommend('album',array('catalogNr' => remU($value)));
						$this->recommend($idx,array('catalogNr' => remU($value)));
						break;
					case 'catalog-source':
						#print_r($matches); die($result);
						$this->recommend('album',
							array(
								'catalogNr' => remU($matches[3]),
								'source' => remU($matches[7])
						));
						$this->recommend($idx,
							array(
								'catalogNr' => remU($matches[3]),
								'source' => remU($matches[7])
						));
						break;
					case 'album-catalog-source':
						#print_r($matches); die($result);
						$this->recommend('album',
							array(
								'title' => remU($matches[1]),
								'catalogNr' => remU($matches[4]),
								'source' => remU($matches[8])
						));
						$this->recommend($idx,
							array(
								'album' => remU($matches[1]),
								'catalogNr' => remU($matches[4]),
								'source' => remU($matches[8])
						));
						break;
					case 'album-catalog':
						#print_r($matches); die($result);
						$this->recommend('album',
							array(
								'title' => remU($matches[1]),
								'catalogNr' => remU($matches[4])
						));
						$this->recommend($idx,
							array(
								'album' => remU($matches[1]),
								'catalogNr' => remU($matches[4])
						));
						break;
					case 'album-source':
						#print_r($matches); die($result);
						$this->recommend('album',
							array(
								'title' => remU($matches[1]),
								'source' => remU($matches[4])
						));
						$this->recommend($idx,
							array(
								'album' => remU($matches[1]),
								'source' => remU($matches[4])
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
		$rgx = new \Slimpd\RegexHelper();

		// maybe we have a year in filename -> add little score
		if(preg_match_all("/".$rgx->mayBracket.$rgx->year.$rgx->mayBracket."/", $value, $matches)) {
			foreach($matches as $i) {
				foreach($i as $x) {
					$this->scoreAttribute($idx, 'year', $x, 0.5);
				}
			}
		}
		// maybe we have a catNr in filename  -> add little score
		if(preg_match_all("/".$rgx->catNr."/", $value, $matches)) {
			foreach($matches as $i) {
				foreach($i as $x) {
					$this->scoreAttribute($idx, 'catalogNr', $x, 0.5);
				}
			}
		}
		
		$iHateRegex = array(
			// 01-Aaron_Dilloway-Untitled.mp3
			'classic' => $rgx->dStart.$rgx->num.$rgx->glue.$rgx->noMinus."-".$rgx->noMinus.$rgx->ext.$rgx->dEnd,
			// A1-Aaron_Dilloway-Untitled.mp3
			'classic-vinyl' => $rgx->dStart.$rgx->vinyl.$rgx->glue.$rgx->noMinus."-".$rgx->noMinus.$rgx->ext.$rgx->dEnd,
			// 112-Aaron_Dilloway-Untitled.mp3
			'classicscene' => $rgx->dStart.$rgx->num.$rgx->glue.$rgx->noMinus."-".$rgx->noMinus.$rgx->scene.$rgx->ext.$rgx->dEnd,
			// B2-Aaron_Dilloway-Untitled-sour.mp3
			'classicscene-vinyl' => $rgx->dStart.$rgx->vinyl.$rgx->glue.$rgx->noMinus."-".$rgx->noMinus.$rgx->scene.$rgx->ext.$rgx->dEnd,

			// V.A. Brazilified - 05 - Mr Gone - Mosquito Coast
			'album-number-artist-title' => $rgx->dStart.$rgx->noMinus.$rgx->glue.$rgx->num.$rgx->glue.$rgx->noMinus.$rgx->glue.$rgx->noMinus.$rgx->ext.$rgx->dEnd,

			// V.A. Brazilified - A1 - Mr Gone - Mosquito Coast
			'album-vinyl-artist-title' => $rgx->dStart.$rgx->noMinus.$rgx->glue.$rgx->vinyl.$rgx->glue.$rgx->noMinus.$rgx->glue.$rgx->noMinus.$rgx->ext.$rgx->dEnd,

			
			// 05-Voodoo_Man.mp3
			'noartist' => $rgx->dStart.$rgx->num.$rgx->glue.$rgx->noMinus.$rgx->ext.$rgx->dEnd,
			// B2-Voodoo_Man_(Last_Break_Mix).mp3
			'noartist-vinyl' => $rgx->dStart.$rgx->vinyl.$rgx->glue.$rgx->noMinus.$rgx->ext.$rgx->dEnd,

			// Aaron_Dilloway_-_Voodoo_Man_(Last_Break_Mix).mp3
			'nonumber' => $rgx->dStart.$rgx->noMinus."-".$rgx->noMinus.$rgx->ext.$rgx->dEnd,

			// Voodoo_Man.mp3
			'nonumber-noartist' => $rgx->dStart.$rgx->noMinus.$rgx->ext.$rgx->dEnd,

			'anything' => $rgx->dStart.$rgx->anything.$rgx->ext.$rgx->dEnd,
		);
		foreach($iHateRegex as $result => $pattern) {
			#cliLog($pattern);
			if(preg_match($pattern, $value, $matches)) {
				if($result !== 'nonumber' && $result !== 'nonumber-noartist') {
					if(is_numeric($matches[1])) {
						$this->extractedTrackNumbers[$idx] = intval($matches[1]);
					} else {
						// vinyl schemed tracknumer
						$this->extractedTrackNumbers[$idx] = strtoupper($matches[1]);
					}
				}

				switch($result) {
					// make recommendations for expected track-attributes
					case 'classic':
					case 'classicscene' :
					case 'classic-vinyl':
					case 'classicscene-vinyl':
						$this->recommend($idx, array(
							'trackNumber' => remU($matches[1]),
							'artist' => remU($matches[2]),
							'title' => remU($matches[3])
						));
						break;
					case 'noartist':
					case 'noartist-vinyl':
						$this->recommend($idx, array(
							'trackNumber' => remU($matches[1]),
							'title' => remU($matches[2])
						));
						break;
					case 'nonumber':
						$this->recommend($idx, array(
							'artist' => remU($matches[1]),
							'title' => remU($matches[2])
						));
						break;
					case 'album-number-artist-title':
					case 'album-vinyl-artist-title':
						$this->recommend($idx, array(
							'album' => remU($matches[1]),
							'trackNumber' => remU($matches[2]),
							'artist' => remU($matches[3]),
							'title' => remU($matches[4])
						));
						$this->recommend('album', array(
							'title' => remU($matches[1])
						));
					case 'nonumber-noartist':
					case 'anything':
						$this->recommend($idx, array(
							'title' => remU($matches[1])
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
			$this->recommend($idx, array('trackNumber' => $value));
			return 'simple'; // 1, 2, 3
		}

		if(ltrim($value,'0') != strval($value)) {
			$this->extractedTrackNumbers[$idx] = intval($value);
			$this->recommend($idx, array('trackNumber' => intval($value)));
			return 'leadingzero';	// 01, 02
		}
		if(preg_match("/^(\d*)\/(\d*)$/", $value, $matches)) {
			$this->extractedTrackNumbers[$idx] = intval($matches[1]);
			$this->extractedTotalTracks[$idx] = intval($matches[2]);
			$this->recommend($idx, array('trackNumber' => intval($matches[1])));
			$this->recommend('album', array('totalTracks' => intval($matches[2])));
			return 'slashsplit'; // 01/12 , 2/12
		}
		if(preg_match("/^([a-zA-Z]{1,2})(?:[\/-]{1})(\d*)$/", $value)) {
			if($idx !== NULL) { 
				$this->extractedTrackNumbers[$idx] = $value;
				$this->recommend($idx, array('trackNumber' => $value, 'source' => 'Vinyl'));
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
			if(preg_match("/^(\d{1})01-/", $range) === 0) {
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
	 * checks if passed number array is gapless
	 * 
	 */
	private function extractNumericRangeness($numbers) {
		$noGaps = range(1,max($numbers));
		return array_diff($noGaps,$numbers);
	}
	
	/**
	 * 
	 * TODO: maybe this function is useful to identify missing tracks of an album!?
	 * (TODO): remove this strange syntax "goto" of copy/pasted method
	 */
	/*
	private function extractNumericRangenessOld($input) {

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
	*/

	
	// setter
	public function setRelDirPathHash($value) {
		$this->relDirPathHash = $value;
	}

	public function setRelDirPath($value) {
		$this->relDirPath = $value;
	}

	public function setDirectoryMtime($value) {
		$this->directoryMtime = $value;
	}

	
	public function addTrack(array $rawTagDataArray) {
		$this->tracks[] = $rawTagDataArray;
	}

	// getter
	public function getRelDirPathHash() {
		return $this->relDirPathHash;
	}

	public function getRelDirPath() {
		return $this->relDirPath;
	}

	public function getDirectoryMtime() {
		return $this->directoryMtime;
	}
}
	