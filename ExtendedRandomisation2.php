<?php
/**
 * REDCap External Module: Extended Randomisation2
 * @author Luke Stevens, Murdoch Children's Research Institute
 */
namespace MCRI\ExtendedRandomisation2;
require_once 'Randomisers/AbstractRandomiser.php';
require_once 'Randomisers/RandomiserConfig.php';

use ExternalModules\AbstractExternalModule;

class ExtendedRandomisation2 extends AbstractExternalModule
{
    public function redcap_module_randomize_record(int $project_id, int $randomization_id, string $record, array $field_values, ?int $group_id )
    {
        if ($this->getProjectSetting('delay') && $this->delayModuleExecution()) return; 

        try {
            $randomiser = $this->makeRandomiser($randomization_id, $record, $field_values, $group_id);
            if ($randomiser instanceof \MCRI\ExtendedRandomisation2\AbstractRandomiser) {
                $result = $randomiser->randomise();
            } else {
                $result = null;
            }

        } catch (\Throwable $th) {
            $randAttr = \Randomization::getRandomizationAttributes($randomization_id);
            $result = "An error occurred in randomization id $randomization_id ";
            \REDCap::logEvent($this->getModuleName(), $result.PHP_EOL.'See external module log for more information.', '', $record, $randAttr['targetEvent'], $project_id);
            $this->log($result.PHP_EOL.$th->getMessage().PHP_EOL.$th->getTraceAsString());

            $failEmails = array_filter($this->getProjectSetting('fail-alert-email'), static function($var){return $var !== null;} );
            if (sizeof($failEmails)>0) {
                global $project_contact_email;
                $subject = "Extended Randomization External Module: Randomization Failed for Record '$record' (pid=$project_id)";
                $body = [$subject];
                $body[] = "";
                $body[] = "Date/time: ".NOW;
                $body[] = "Project: $project_id";
                $body[] = "Randomization ID: $randomization_id";
                $body[] = "Record: $record";
                $body[] = "";
                $body[] = "Check the project's External Module Logging page for more information.";
                
                $email = new \Message();
                $email->setFrom($project_contact_email);
                $email->setTo(implode(',', $failEmails));
                $email->setSubject($subject);
                $email->setBody(implode('<br>',$body), true);
                $email->send();
            }
        }
        return $result;
    }

    protected function makeRandomiser($randomization_id, string $record, array $strata_field_values, ?int $group_id): ?AbstractRandomiser {
        list($thisRidKey, $randomiserType, $thisRandConfigText) = $this->getRandConfigSettings($randomization_id);

        if (is_null($thisRidKey) || $randomiserType==='Default') return null; // no EM config this rand
        
        if (file_exists(dirname(__FILE__)."/Randomisers/$randomiserType.php")) {
            require_once "Randomisers/$randomiserType.php";
            $classname = __NAMESPACE__."\\$randomiserType";
        } else {
            throw new \Exception("Could not read module config for randomization id $randomization_id");
        }

        $configArray = \json_decode($thisRandConfigText, true) ?? array();
        if (!is_array($configArray)) throw new \Exception("Could not read module config for randomization id $randomization_id, '$randomiserType'");

        $validate = $classname::validateConfigSettings($configArray);
        if ($validate!==true) {
            throw new \Exception($validate);
        }

        return new $classname($randomization_id, $configArray, $this, $record, $strata_field_values, $group_id);
    }

    /**
     * getRandConfigSettings
     * Read the module config settings for the specified randomisation
     * @param $rid The unique randomisation id top read the module settings for
     * @return array (int key in config array or false if not yet present; string randomiser class name; string config text or empty)
     */
    protected function getRandConfigSettings($rid) : array {
        $randConfig = $this->getSubSettings('project-rand-config');
        $rtnKey = false;
        $rtnClass = '';
        $rtnText = '';
        foreach($randConfig as $key => $randSettings) {
            $thisRid = $randSettings['rand-id'];
            if ($rid==$thisRid) {
                $rtnKey = $key;
                $rtnClass = $randSettings['rand-class'];
                $rtnText = $randSettings['rand-config'];
                break;
            }
        }
        return array($rtnKey,$rtnClass,$rtnText);
    }

    /**
     * redcap_module_page_ajax()
     * Save module config for specific randomisation on Setup page
     */
    public function redcap_module_ajax($action, $payload, $project_id, $record, $instrument, $event_id, $repeat_instance, $survey_hash, $response_id, $survey_queue_hash, $page, $page_full, $user_id, $group_id) {
        $result = false;
        $message = 'Saved';
        try {
            if (!is_array($payload)) throw new \Exception('Unexpected payload');
            if (array_key_exists('rid', $payload)) {
                $rid = $payload['rid'];
                unset($payload['rid']);
            } else {
                throw new \Exception('Unknown randomization');
            }
            $randAttrs = \Randomization::getRandomizationAttributes($rid, $project_id);
            if ($randAttrs===false) throw new \Exception('Unknown randomization');

            if (!array_key_exists('randomiser_class', $payload)) throw new \Exception('Unknown randomization class');
            $randomiser = $payload['randomiser_class'];
            unset($payload['randomiser_class']);

            if ($randomiser!='Default') {
                if (file_exists(dirname(__FILE__)."/Randomisers/$randomiser.php")) {
                    require_once "Randomisers/$randomiser.php";
                    $classname = __NAMESPACE__.'\\'.$randomiser;
                } else {
                    throw new \Exception("Unknown randomization class");
                }
        
                $validate = $classname::validateConfigSettings($payload);
                if ($validate!==true) {
                    throw new \Exception($validate);
                }
            }

            $project_settings = $this->getProjectSettings($project_id);

            list($thisRidKey, $thisClass, $thisRandConfigText) = $this->getRandConfigSettings($rid);

            if ($thisRidKey===false) {
                $project_settings['project-rand-config'][] = 'true';
                $project_settings['rand-id'][] = "$rid";
                $project_settings['rand-class'][] = $randomiser;
                $project_settings['rand-config'][] = (empty($payload)) ? '' : \json_encode($payload, JSON_FORCE_OBJECT);
                $project_settings['rand-state'][] = '';
            } else {
                $project_settings['project-rand-config'][$thisRidKey] = 'true';
                $project_settings['rand-id'][$thisRidKey] = "$rid";
                $project_settings['rand-class'][$thisRidKey] = $randomiser;
                $project_settings['rand-config'][$thisRidKey] = (empty($payload)) ? '' : \json_encode($payload, JSON_FORCE_OBJECT);
            }
            $this->setProjectSettings($project_settings, $project_id);
            $result = true;
        } catch (\Throwable $th) {
            $message = $th->getMessage();
        }
            return array('result'=>$result, 'message'=>$message);
    }

    /**
     * redcap_every_page_before_render()
     * Catch a randomisation model being deleted and remove any module config for that particular randomisation
     */
    public function redcap_every_page_before_render($project_id) {
        if (!defined('PAGE') || PAGE!='"Randomization/save_randomization_setup.php"') return;
        if (!isset($_POST['action']) || $_POST['action']!=='erase') return;
        if (!isset($_POST['rid'])) return;
        $rid = \Randomization::getRid($_POST['rid'], $project_id);
        if ($rid===false) return;

        list($thisRidKey, $thisClass, $thisRandConfigText) = $this->getRandConfigSettings($rid);

        if ($thisRidKey!==false) {
            $project_settings = $this->getProjectSettings();
            unset($project_settings['project-rand-config'][$thisRidKey]);
            unset($project_settings['rand-id'][$thisRidKey]);
            unset($project_settings['rand-class'][$thisRidKey]);
            unset($project_settings['rand-config'][$thisRidKey]);
            unset($project_settings['rand-state'][$thisRidKey]);
            if (sizeof($project_settings['project-rand-config']) === 0) {
                $project_settings['project-rand-config'] = array(0=>null);
                $project_settings['rand-id'] = array(0=>null);
                $project_settings['rand-class'] = array(0=>null);
                $project_settings['rand-config'] = array(0=>null);
                $project_settings['rand-state'] = array(0=>null);
            } else {
                $project_settings['project-rand-config'] = array_values($project_settings['project-rand-config']);
                $project_settings['rand-id'] = array_values($project_settings['rand-id']);
                $project_settings['rand-class'] = array_values($project_settings['rand-class']);
                $project_settings['rand-config'] = array_values($project_settings['rand-config']);
                $project_settings['rand-state'] = array_values($project_settings['rand-state']);
            }
            $this->setProjectSettings($project_settings, $project_id);
        }
    }

    /**
     * redcap_every_page_top()
     * - Add tab to Dashboard page for batch randomisation
     * - Add config options to setup page
     */
    public function redcap_every_page_top($project_id) {
        if (!defined('USERID')) return;
        if (PAGE=='Randomization/index.php' && isset($_GET['rid'])) $this->setupPage($_GET['rid']);
        if (PAGE=='Randomization/dashboard.php' && isset($_GET['rid'])) $this->dashboardPage($_GET['rid']);
    }

    public function setupPage($rid): void {
        if ($rid=='new') {
            $opacity = 'opacity: 0.5;';
            $disabled = 'disabled';
            $envelope = '';
            $extRndOpts = array(); 
            $extRndOpt = '';
            $extRndOptsConfig = '';
            $ridConfig = array(); 
        } else {
            $attrs = \Randomization::getRandomizationAttributes($rid);
            $opacity = '';
            $disabled = '';
            if ($attrs['isBlinded']) {
                $envelope = '<i class="fas fa-envelope text-danger fs14 mr-1" data-bs-toggle="tooltip" title="'.\RCView::tt('random_162',false).'"></i>';
            } else {
                $envelope = '<i class="fas fa-envelope-open text-success fs14 mr-1" data-bs-toggle="tooltip" title="'.\RCView::tt('random_161',false).'"></i>';
            }
            list($extRndOpts, $extRndOptsConfig) = AbstractRandomiser::setupPageContent($rid);

            list($thisRidKey, $thisClass, $thisRandConfigText) = $this->getRandConfigSettings($rid);
            $extRndOpt = (empty($thisClass)) ? 'Default' : $thisClass;
            $ridConfig = \json_decode($thisRandConfigText, true) ?? array();
        }

        print \RCView::div(array('id'=>'extrnd-step5div','class'=>'round chklist extrnd-init-hidden','style'=>'background-color:#eee;border:1px solid #ccc;padding:5px 15px 15px;'.$opacity),
                    \RCView::p(array('style'=>'color:#800000;font-weight:bold;font-size:13px;'), \RCView::span(array(),'STEP 5: <i class="fas fa-cube mr-1"></i>Extended Randomization Options')) .
                    \RCView::p(array('style'=>''), \RCView::span(array(),'Additional configuration options provided by the <em><i class="fas fa-cube mr-1"></i>Extended Randomization</em> external module.')) .
                    \RCView::div(array(),
                        \RCView::span(array('class'=>'font-weight-bold', 'style'=>'display:inline-block;min-width:120px;'), \RCView::span(array(),$envelope."Randomization option")) . 
                        \RCView::select(array('class'=>'mx-2','style'=>'max-width:400px;', 'id'=>'extrnd-opt', 'name'=>'extrnd-opt', $disabled=>''), $extRndOpts, $extRndOpt) .
                        \RCView::button(array('id'=>'extrnd-opt-save','class'=>'btn btn-xs btn-defaultrc'), '<i class="fas fa-cog mr-1"></i>'.\RCView::tt('random_207')) . 
                        \RCView::span(array('id'=>'extrnd-opt-savedmsg','class'=>'ml-1'), '') .
                        \RCView::input(array('type'=>'hidden','name'=>'extrnd-rid','value'=>$rid))
                    ) .
                    \RCView::div(array(),$extRndOptsConfig)
                );
        $this->initializeJavascriptModuleObject();
        ?>
        <style type="text/css">
            .extrnd-init-hidden { display: none; }
        </style>
        <script type="text/javascript">
            let module = <?=$this->getJavascriptModuleObjectName()?>;
            module.rid = <?=$rid?>;
            module.randomiser = '<?=$extRndOpt?>';
            module.randomiserConfig = JSON.parse('<?=\json_encode($ridConfig, JSON_FORCE_OBJECT)?>');
            module.selectRandomiser = function() {
                module.randomiser = $(this).val();
                module.readRandomiserSettings();
                $('div.extrnd-opt-config').hide();
                $('#extrnd-opt-config-'+module.randomiser).show();
                $('#extrnd-opt-save').prop('disabled', false);
            };
            module.configEdited = function() {
                $('#extrnd-opt-save').prop('disabled', false);
            };
            module.readRandomiserSettings = function() {
                module.randomiserConfig = {
                    'rid': module.rid,
                    'randomiser_class': module.randomiser
                };
                $('#extrnd-opt-config-'+module.randomiser+' input').each(function(i,e){
                    let thisName = $(e).attr('name');
                    module.randomiserConfig[thisName] = $(e).val();
                });
            };
            module.setRandomiserSettings = function() {
                for (var setting in module.randomiserConfig) {
                    if (module.randomiserConfig.hasOwnProperty(setting)) {
                        $('#extrnd-opt-config-'+module.randomiser+' input[name='+setting).val(module.randomiserConfig[setting]);
                    }
                }
            };
            module.saveRandomiserConfig = function() {
                $('#extrnd-opt-save').prop('disabled', true);
                $('#extrnd-opt-savedmsg').removeClass('text-success text-danger').html('');
                
                module.readRandomiserSettings();
                console.log(module.randomiserConfig);

                module.ajax('save-randomiser-config', module.randomiserConfig).then(function(data) {
                    if (!data.hasOwnProperty('result')) data.result = 0;
                    if (!data.hasOwnProperty('message')) data.message = window.woops;
                    if (data.result) {
                        $('#extrnd-opt-savedmsg').addClass('text-success').html('<i class="fas fa-check-circle mr-1"></i>'+data.message).show();
                    } else {
                        $('#extrnd-opt-savedmsg').addClass('text-danger').html('<i class="fas fa-exclamation-triangle mr-1"></i>'+data.message).show();
                        $('#extrnd-opt-save').prop('disabled', false);
                        console.log(data.message);
                    }
                    setTimeout(function(){
                        $('#extrnd-opt-savedmsg').fadeOut(1000).html('');
                    }, 5000);
                });

            };
            module.init = function() {
                module.setRandomiserSettings();
                $('#extrnd-opt').on('change', module.selectRandomiser).trigger('change');
                $('#extrnd-opt-config-'+module.randomiser+' input').on('change', module.configEdited);
                $('#extrnd-opt-save').prop('disabled', true).on('click', module.saveRandomiserConfig);
                $('#extrnd-step5div').insertAfter('#step4div').show();
            };
            $(document).ready(function(){
                module.init();
            });
        </script>   
        <?php
    }

    public function dashboardPage($rid): void {
        $url = $this->getUrl('batch.php',false,false).'&rid='.intval($rid);
        ?>
        <script type="text/javascript">
            $(document).ready(function(){
                let tab = $('#sub-nav li:last').clone();
                $(tab).find('a:first').attr('href', '<?=$url?>').find('span:first').html('Batch Randomisation');
                $(tab).removeClass('active').appendTo('#sub-nav ul');
            });
        </script>   
        <?php
    }

    public function batchRandomisationPage($rid): void {
        echo '<div class="projhdr"><i class="fas fa-random"></i> Randomization</div>';
        echo '<p></p>';
        $this->renderTabs($rid);
        echo 'batch';
    }

    // Render the tabs at top of Batch Randomization page based upon user rights
	public function renderTabs($rid)
	{
		global $user_rights,$project_id;
        $url = $this->getUrl('batch.php',false,false)."&rid=$rid";
        $url = substr($url, strpos($url, 'ExternalModules/'));
        $url = str_replace('&pid='.$project_id,'',$url);
        $url = str_replace('ExternalModules/?','ExternalModules/index.php?',$url);
        $_SERVER['REQUEST_URI'] = str_replace('ExternalModules/?','ExternalModules/index.php?',$_SERVER['REQUEST_URI']); // for active tab detection
		$tabs = array();
        $summaryPage = 'Randomization/index.php';
        $tabs[$summaryPage] = \RCView::tt('random_154'); // Summary (nb. index.php or dashboard.php to handle users with dash-only rights)
        $sequenceBadge = \RCView::span(array('class'=>'badge bg-secondary ml-1'), \Randomization::getSequenceFromRid($rid));
        if ($user_rights['random_setup']) {
            $tabs["Randomization/index.php?rid=$rid"] = \RCView::tt('random_48').$sequenceBadge;
        }
        $tabs["Randomization/dashboard.php?rid=$rid"] = \RCView::tt('rights_143').$sequenceBadge;
        $tabs[$url] = 'Batch Randomisation'.$sequenceBadge;
		\RCView::renderTabs($tabs);
	}

}