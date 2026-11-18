<?php

namespace OPEN\PainDraw ;

use REDCap ;
use Files ;

class PainDraw extends \ExternalModules\AbstractExternalModule {
    
	private ?string $_survey_hash=Null ;

	private const ALLOWED_MIME_TYPES = [
		'image/jpeg',
		'image/png',
		'image/webp',
		'image/avif',
		'image/bmp',
	];

	function redcap_data_entry_form(int $project_id, ?string $record, string $instrument): void 
	{
		$cfg = $this->parseModuleSettings($project_id, $instrument);
		$cfg['survey'] = false ;
		if( array_key_exists('img_url', $cfg) ) {
			
			$this->setJsSettings($cfg) ;
			$this->includeJs('js/paindraw.js');
		}
	}
	
	function redcap_survey_page(int $project_id, ?string $record, string $instrument, int $event_id, ?int $group_id, string $survey_hash): void
	{
		$this->_survey_hash = $survey_hash ;
		$cfg = $this->parseModuleSettings($project_id, $instrument);
		$cfg['survey'] = true ;
		if( array_key_exists('img_url', $cfg) ) {
			$this->setJsSettings($cfg) ;
			$this->includeJs('js/paindraw.js');
		}
		
	}

	function redcap_save_record(int $project_id, string $record, string $instrument, int $event_id, ?int $group_id, ?string $survey_hash, ?int $response_id, ?int $repeat_instance): void
	{

		if( ! array_key_exists('paindraw_stored_strokes', $_POST ) ){
			return ;
		}

		if( ! $_POST['paindraw_stored_strokes'] ) {
			return ;
		}

		$paindraw_stored_strokes = json_decode($_POST['paindraw_stored_strokes'], true);
		unset($_POST['paindraw_stored_strokes']) ;

		if( ! is_array($paindraw_stored_strokes) ) {
 			error_log("ERROR - PainDraw REDCap external module: Client sent stroke data that is not a JSON list. Data not saved.");
    		return ;
		}

		try {
			$validated_strokes = $this->validateJsonInput($paindraw_stored_strokes);
		} catch (\ValidationException $e) {
			error_log("ERROR - PainDraw REDCap external module: " . $e );
			return ;
		}

		if( ! is_array($validated_strokes) ) {
			error_log("ERROR - PainDraw REDCap external module: Client sendt json data that could not be validated. Data not saved.");
			return ;
		}

		$cfg = $this->parseModuleSettings($project_id, $instrument ) ;
		
		if( empty($cfg['img_url']) ) {
			return ;
		}

		$unique_drawing_id = "PID{$project_id}-R{$record}-F{$cfg['canvas_field']}-E{$event_id}-I{$repeat_instance}" ;
		$paindraw_version = $this->getModuleDirectoryName() ;

		$paindraw_data = array(
			"v" => $paindraw_version, #Henviser til version af eksternt modul
			"id" => $unique_drawing_id, # unikt id for hver tegning - kan også bruges som filnavn måske
			"w" => $cfg['canvas_width'],
			"h" => $cfg['canvas_height'],
			"units" => "px",
			"tpl" => $cfg['tpl'],
			"tpl_md5" => $cfg['tpl_md5'],
			"view" => NULL, # Angivelse af visning (A=Anterior, P=Posterior, L=Venstre, R=Højre)
			"app" => "PainDraw EM",
			"ts" => gmdate("Y-m-d\TH:i:s\Z"),
			"crc" => NULL, # Ukendt parrameter
			"s" => $validated_strokes,
		) ;

		$tmp = tmpfile() ; 
		fwrite($tmp, json_encode($paindraw_data, true ) . "\r\n" ) ;
		$metaData = stream_get_meta_data($tmp) ;

		$docid = REDCap::storeFile($metaData['uri'], $project_id, $unique_drawing_id . '.json' ) ;
		$status = REDCap::addFileToField($docid, $project_id, $record, $cfg['canvas_field'], $event_id, $repeat_instance ) ;

		if ( $docid == 0 or $status == false ) {
			$status = $status ? 'true' : 'false';
			error_log("ERROR - PainDraw REDCap external module: Not able to store json data on project. Could create file: {$docid} Could link to project: {$status}");
		}
		
		// Save PNG image to field.
		if( array_key_exists('image_store_field', $cfg) ) {
			
			$imgUrl = $_POST['paindraw_stored_png'] ?? ''; 
			unset($_POST['paindraw_stored_png']) ;

			if ( is_string($imgUrl) && preg_match('#^data:(image/png);base64,(.*)$#s', $imgUrl, $matches)) {

				$mimeType   = $matches[1]; // should always be image/png because of regexpr
				$base64Data = $matches[2];

				// Fjern evt. whitespace / line breaks i base64-data
    			$base64Data = preg_replace('/\s+/', '', $base64Data);

				$binaryData = base64_decode($base64Data, true);

				if ($binaryData === false) {
					error_log("ERROR - PainDraw REDCap external module: PNG data is not valid base64. PNG not saved.");
					return ;
				}

				if( $this->iMagickInstalled() ){
					$image = new \Imagick() ;
					$image->readImageBlob($binaryData);

					$image->setImageProperty('Author', $paindraw_version );
					$image->setImageProperty('Draw_id', $unique_drawing_id );

					$image->setImageFormat('png');

					$binaryData = $image->getImagesBlob();
				}
				
				$tmpFile = tmpfile();
				fwrite($tmpFile, $binaryData);
				$metaData = stream_get_meta_data($tmpFile) ;

				$pngid = REDCap::storeFile($metaData['uri'], $project_id, $unique_drawing_id . '.png'  ) ;
				$status = REDCap::addFileToField($pngid, $project_id, $record, $cfg['image_store_field'], $event_id, $repeat_instance ) ;

				if ( $pngid == 0 or $status == false ) {
					$status = $status ? 'true' : 'false';
					error_log("ERROR - PainDraw REDCap external module: Not able to store PNG-file on project. Could create file: {$pngid} Could link to project: {$status}");
				}

			}
		}

		
		
	}
	
	function redcap_module_save_configuration($project_id): void
	{

		$settings = $this->getProjectSettings() ;

		foreach($settings['image_file'] as $i => $docid ){
			
		    list ($mimeType, $docName, $fileContent) = REDCap::getFile($docid) ;
			
			$settings['image_file_name'][$i] = $docName ;

			$img_dim = in_array($mimeType, self::ALLOWED_MIME_TYPES, true) ? getimagesizefromstring($fileContent) : false ;
			if ( $img_dim === false || $img_dim[0] < 1 || $img_dim[1] < 1 ) {
				// Unsupported MIME type, or a file that is not a decodable image								
				$settings['image_md5'][$i] = "" ;
				$settings['canvas_width'][$i] = "" ;
				$settings['canvas_height'][$i] = "" ;
				$settings['format_error'][$i] = 1 ;
				
			} else {

				$settings['format_error'][$i] = 0 ;
				$settings['image_md5'][$i] = md5($fileContent) ;
				
				$width = $settings['canvas_width'][$i] ;
				$height = $settings['canvas_height'][$i] ;

				$width  = ($width  && $width  > 0) ? (int)$width  : null;
				$height = ($height && $height > 0) ? (int)$height : null;

				$nativeWidth = $img_dim[0] ;
				$nativeHeight = $img_dim[1] ;
				$maxWidth = 796;

				if ($width === null && $height === null) {
					$width = $nativeWidth;
					$height = $nativeHeight;

					// clamp til maxWidth hvis native er større
					if ($width > $maxWidth) {
						$ratio = $nativeHeight / $nativeWidth;
						$width = $maxWidth;
						$height = (int) round($width * $ratio);
					}

					$settings['canvas_width'][$i] = (string) $width;
					$settings['canvas_height'][$i] = (string) $height;
				}

				elseif ($width !== null && $height === null) {
					$ratio  = $nativeHeight / $nativeWidth;
					$settings['canvas_height'][$i] = (string) round($width * $ratio);
				}

				elseif ($width === null && $height !== null) {
					$ratio = $nativeWidth / $nativeHeight;
					$width = (int) round($height * $ratio);

					// clamp width hvis den overstiger max
					if ($width > $maxWidth) {
						$width = $maxWidth;
						$height = (int) round($width * $nativeHeight / $nativeWidth);
					}

					$settings['canvas_width'][$i] = (string) $width ;
					$settings['canvas_height'][$i] = (string) $height ;
				}

			}

		}

		$this->setProjectSettings($settings) ;
	}

	/**
 	* Validates the strokes posted by paindraw.js and returns a copy that holds
	* only the known keys, with normalised types.
	*
	* @throws \ValidationException
	*/
	protected function validateJsonInput(array $data): array {

		$validated_data = [] ;

		foreach($data as $d ) {

			$v = array() ;

			if( isset($d['strokeId']) ) {
				if( preg_match('/^\d+$/', $d['strokeId'] ) ) {
					$v['strokeId'] = (int)$d['strokeId'] ;
				} else {
					throw new \ValidationException("PainDraw recieved invalid JSON from user") ;
				}
			} else {
				throw new \ValidationException("PainDraw recieved invalid JSON from user") ;
			}

			if( isset($d['drawInputType']) ) {
				if( $d['drawInputType'] === "mouse" || $d['drawInputType'] === "touch" ) {
					$v['drawInputType'] = $d['drawInputType'] ;
				} else {
					throw new \ValidationException("PainDraw recieved invalid JSON from user") ;
				}
			} else {
				throw new \ValidationException("PainDraw recieved invalid JSON from user") ;
			}

			if( isset($d['tool']) ) {
				if( $d['tool'] === "spray" || $d['tool'] === "pen" ) {
					$v['tool'] = $d['tool'] ;
				} else {
					throw new \ValidationException("PainDraw recieved invalid JSON from user") ;
				}
			} else {
				throw new \ValidationException("PainDraw recieved invalid JSON from user") ;
			}

			if( isset($d['color']) ) {
				if( preg_match('/^#([A-Fa-f0-9]{6})$/', $d['color'] ) ) {
					$v['color'] = $d['color'] ;
				} else {
					throw new \ValidationException("PainDraw recieved invalid JSON from user") ;
				}
			} else {
				throw new \ValidationException("PainDraw recieved invalid JSON from user") ;
			}

			if( isset($d['lineWidth']) ) {
				if( preg_match('/^\d+$/', $d['lineWidth'] ) ) {
					$v['lineWidth'] = (int)$d['lineWidth'] ;
				} else {
					throw new \ValidationException("PainDraw recieved invalid JSON from user") ;
				}
			} else {
				throw new \ValidationException("PainDraw recieved invalid JSON from user") ;
			}

			if( $d['tool'] === "spray" ) {
				if( isset($d['sprayDensity']) ) {
					if( preg_match('/^\d+$/', $d['sprayDensity'] ) ) {
						$v['sprayDensity'] = (int)$d['sprayDensity'] ;
					} else {
						throw new \ValidationException("PainDraw recieved invalid JSON from user") ;
					}
				} else {
					throw new \ValidationException("PainDraw recieved invalid JSON from user") ;
				}

				if( isset($d['sprayRadius']) ) {
					if( preg_match('/^\d+$/', $d['sprayRadius'] ) ) {
						$v['sprayRadius'] = (int)$d['sprayRadius'] ;
					} else {
						throw new \ValidationException("PainDraw recieved invalid JSON from user") ;
					}
				} else {
					throw new \ValidationException("PainDraw recieved invalid JSON from user") ;
				}
			} else {
				$v['sprayDensity'] = null;
				$v['sprayRadius'] = null;
			}
			
			if( isset($d['alpha']) ) {
				if( preg_match('/^(0(\.[0-9]{1,2})?|1(\.0{1,2})?)$/', $d['alpha'] ) ) {
					$v['alpha'] = (float)$d['alpha'] ;
				} else {
					throw new \ValidationException("PainDraw recieved invalid JSON from user") ;
				}
			} else {
				throw new \ValidationException("PainDraw recieved invalid JSON from user") ;
			}

			if( isset($d['scaleFactor']) ) {
				if( preg_match('/^[0-9]+(\.[0-9]+)?$/', $d['scaleFactor'] ) ) {
					$v['scaleFactor'] = (float)$d['scaleFactor'] ;
				} else {
					throw new \ValidationException("PainDraw recieved invalid JSON from user") ;
				}
			} else {
				throw new \ValidationException("PainDraw recieved invalid JSON from user") ;
			}

			if( isset($d['dpr']) ) {
				if( preg_match('/^[0-9]+(\.[0-9]+)?$/', $d['dpr'] ) ) {
					$v['dpr'] = (float)$d['dpr'] ;
				} else {
					throw new \ValidationException("PainDraw recieved invalid JSON from user") ;
				}
			} else {
				throw new \ValidationException("PainDraw recieved invalid JSON from user") ;
			}

			if( isset($d['p']) ){

				if( ! is_array($d['p']) ||  empty($d['p']) ){
					throw new \ValidationException("PainDraw recieved invalid JSON from user") ;
				}
					
				foreach( $d['p'] as $pair ) {
				
					if (!is_array($pair) || count($pair) !== 2) {
						throw new \ValidationException("PainDraw recieved invalid JSON from user") ;
					}

					[$x, $y] = $pair;

					if (!is_int($x) || !is_int($y)) {
						throw new \ValidationException("PainDraw recieved invalid JSON from user") ;
					}

				}

				$v['p'] = $d['p'] ;

			} else {
				throw new \ValidationException("PainDraw recieved invalid JSON from user") ;
			}

			array_push($validated_data, $v);

		}

		return $validated_data ;

	}

	protected function includeJs($file) {
        // Use this function to use your JavaScript files in the frontend
		echo '<script src="' . $this->getUrl($file) . '"></script>';
    }

	protected function setJsSettings($settings) {
        echo "<script>const paindraw_cfg = " . json_encode($settings) . ";</script>";
		$this->initializeJavascriptModuleObject();
		$this->tt_transferToJavascriptModuleObject(array("reset_btn","download_btn"));
		echo "<script> paindraw = " . $this->framework->getJavascriptModuleObjectName() . "</script>" ; 
    }

	protected function parseModuleSettings($project_id, $instrument=NULL) {
		
		$settings = array() ;
		$groups = $this->getSubSettings('group_of_fields') ;
		foreach( $groups as $key => $group ){
			
			if( isset($group['instrument']) ) {
				
				if( $group['instrument'] != $instrument ) continue ;
				
				
				if( isset($group['image_file']) ){

					$settings['canvas_field'] = $group['canvas_field'] ;
				
					if( $group['format_error'] == 1 ) {
						$settings['img_url'] = false ;
						break ;
					}
					
					$settings['tpl'] = $group['image_file_name'] ;
					$settings['tpl_md5'] = $group['image_md5'] ;

				    //generating img link from doc_id
					$doc_id_hash = Files::docIdHash($group['image_file']) ;
					$img_url = APP_PATH_WEBROOT . "DataEntry/image_view.php" ;
					$img_url .= "?doc_id_hash=" . $doc_id_hash ;
					$img_url .= "&pid=" . $project_id ;
					$img_url .= "&id=" . $group['image_file'] ;

					if( $this->_survey_hash ) {
						$img_url .= "&s=" . $this->_survey_hash ;
					}

					//Populating settings object for java script
					$settings['img_url'] = $img_url ;
					

					if( isset($group['image_store_field']) ){
						$settings['image_store_field'] = $group['image_store_field'] ;
					} 

					$settings['canvas_width'] = $this->requireInteger($group['canvas_width']) ;
					$settings['canvas_height'] = $this->requireInteger($group['canvas_height']) ;
										
					if( isset($group['spray_radius']) ){
						$settings['spray_radius'] = $this->requireInteger($group['spray_radius']) ;
					} else {
						$settings['spray_radius'] = 5 ;
					}

					if( isset($group['spray_density']) ){
						$settings['spray_density'] = $this->requireInteger($group['spray_density']) ;
					} else {
						$settings['spray_density'] = 15 ;
					}

					if( isset($group['line_width']) ){
						$settings['line_width'] = $this->requireInteger($group['line_width']) ;
					} else {
						$settings['line_width'] = 3 ;
					}
										
					if( isset($group['line_color']) ){
						if ( is_string($group['line_color']) && preg_match('/^#([a-f0-9]{3}|[a-f0-9]{6})$/iD', $group['line_color'], $color_match ) ) {
    						$settings['line_color'] = strlen($color_match[1]) === 3
        					? '#' . preg_replace('/(.)/', '$1$1', $color_match[1])
        					: $group['line_color'] ;
						} else {
							$settings['line_color'] = '#fc3d03' ;
						}
					} else {
						$settings['line_color'] = '#fc3d03' ;
					}
					
					if( isset($group['draw_tool']) ){
						$settings['draw_tool'] = $group['draw_tool'] ;
					} else {
						$settings['draw_tool'] = 'spray' ;
					}

					if( isset($group['transparancy']) ){
						$settings['alpha'] = round( 1 - ( $this->requireInteger($group['transparancy']) / 100 ) , 2 );
					} else {
						$settings['alpha'] = 0.1 ;
					}

	 			}
				
				break ;
			}
		}

		return $settings ;

	}

	function iMagickInstalled(): bool
    {
        return class_exists('Imagick');
    }
}	

?>