<?php
/**
 * REDCap External Module: Extended Randomisation2
 * @author Luke Stevens, Murdoch Children's Research Institute
 */
namespace MCRI\ExtendedRandomisation2;
require_once 'Randomisers/AbstractRandomiser.php';
require_once 'Randomisers/REDCapDefault.php';

use ExternalModules\AbstractExternalModule;

class ExtendedRandomisation2 extends AbstractExternalModule
{
    const REDCAP_DEFAULT = 'REDCapDefault';
    const SHOW_DISABLED_BATCH_TAB = true;
    private $randAttrs;
    
    //region External Module Framework methods
    /**
     * redcap_module_randomize_record()
     */
    public function redcap_module_randomize_record(int $project_id, int $randomization_id, string $record, array $field_values, ?int $group_id )
    {
        if ($this->getProjectSetting('delay') && $this->delayModuleExecution()) return; 

        try {
            $randomiser = $this->makeRandomiser($randomization_id);
            if ($randomiser instanceof \MCRI\ExtendedRandomisation2\AbstractRandomiser) {
                $randomiser->initialiseRecordRandomiser($record, $field_values, $group_id);
                if ($randomiser->getNextAid()==='0') {
                    $result = '0'; // return allocation table exhausted
                } else {
                    $result = $randomiser->randomise();
                }
            } else {
                $result = null;
            }

        } catch (\Throwable $th) {
            $result = "An error occurred in randomization id $randomization_id ";
            $this->logError($randomization_id, $result, $record, $th);
        }
        return $result;
    }

    /**
     * redcap_module_page_ajax()
     * - Save module config for specific randomisation on Setup page
     * - Randomise record from Batch page
     */
    public function redcap_module_ajax($action, $payload, $project_id, $record, $instrument, $event_id, $repeat_instance, $survey_hash, $response_id, $survey_queue_hash, $page, $page_full, $user_id, $group_id) {
        switch ($action) {
            case 'save-randomiser-config': return $this->ajaxSaveConfig($payload, $project_id); break;
            case 'randomise-record': return $this->ajaxRandomiseRecord($payload, $project_id); break;
            default: break;
        }
        return array('result'=>false, 'message'=>'action function not implemented');
    }

    /**
     * redcap_every_page_before_render()
     * - Catch a randomisation model being deleted and remove any module config for that particular randomisation
     * - Catch an "Erase all data" and clear any module seed sequence value and state storage
     */
    public function redcap_every_page_before_render($project_id) {
        if (!defined('PAGE')) return;
        if (isset($_POST['submit-action']) ) {
            if (in_array($_POST['submit-action'], array('submit-btn-saverecord','submit-btn-savecontinue','submit-btn-savenextrecord','submit-btn-saveexitrecord'))) {
                $this->applyFieldProtection();
            }
            
        } else if (isset($_POST['action'])) {
            if (PAGE=='Randomization/save_randomization_setup.php' && $_POST['action']=='erase') $this->removeRidModuleConfig($project_id);
            if (PAGE=='ProjectGeneral/erase_project_data.php' && $_POST['action']=='erase_data') $this->resetModuleState($project_id);
        }
    }

    /**
     * applyFieldProtection()
     * Do not allow fields capturing logging of randomisation algorithm to be overwritten with empty text value during regular saves. 
     * (E.g. when saving a randomisation form after a randomise ajax call.)
     */
    protected function applyFieldProtection(): void {
        $projectRandomisations = \Randomization::getAllRandomizationAttributes();
        foreach (array_keys($projectRandomisations) as $rid) {
            list($thisRidKey, $randomiserType, $thisRandConfigText) = $this->getRandConfigSettings($rid);
            if (!empty($randomiserType) && $randomiserType != self::REDCAP_DEFAULT) {
                $thisRandomiser = $this->makeRandomiser($rid, true);
                foreach ($thisRandomiser->getProtectedFields() as $protectedField) {
                    if (isset($_POST[$protectedField]) && $_POST[$protectedField]=='') {
                        unset($_POST[$protectedField]);
                    }
                }
            }
        }
    }

    /**
     * removeRidModuleConfig()
     * Catch a randomisation model being deleted and remove any module config for that particular randomisation
     */
    protected function removeRidModuleConfig($project_id) {
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
     * resetModuleState()
     * Catch an "Erase all data" and clear any module seed sequence value and state storage
     */
    protected function resetModuleState($project_id) {
        $ridStateSettings = $this->getProjectSetting('rand-state');
        foreach (array_keys($ridStateSettings) as $index) {
            $ridStateSettings[$index] = '';
        }
        $this->setProjectSetting('rand-state', $ridStateSettings);
        $this->setProjectSetting('seed-sequence', '');
    }

    /**
     * redcap_every_page_top()
     * - Add tab to Dashboard page for batch randomisation
     * - Add config options to setup page
     */
    public function redcap_every_page_top($project_id) {
        if (!defined('USERID')) return;
        if (PAGE=='Randomization/index.php' && !isset($_GET['rid'])) $this->summaryPage();
        if (PAGE=='Randomization/index.php' && isset($_GET['rid'])) $this->setupPage($this->escape($_GET['rid']));
        if (PAGE=='Randomization/dashboard.php' && isset($_GET['rid'])) $this->dashboardPage($this->escape($_GET['rid']));
    }
    //endregion

    //region Page content
    /**
     * summaryPage()
     * Additional content for Randomisation Setup page
     * - Indicator icons for Allocation Type
     */
    public function summaryPage(): void {
        $projectRandomisations = \Randomization::getAllRandomizationAttributes();
        $extrndIcons = array();
        foreach (array_keys($projectRandomisations) as $index => $rid) {
            list($thisRidKey, $randomiserType, $thisRandConfigText) = $this->getRandConfigSettings($rid);
            if (!empty($randomiserType) && $randomiserType != self::REDCAP_DEFAULT) {
                $thisRandomiser = $this->makeRandomiser($rid, false); // only need the randomiser name - don't apply config as may not be properly set yet
                $thisRandIconProp = new \stdClass();
                $thisRandIconProp->rowIndex = $index;
                $thisRandIconProp->label = $thisRandomiser->getConfigOptionLabel();
                $extrndIcons[] = $thisRandIconProp;
            }
        }
        if (count($extrndIcons)===0) return;

        $this->initializeJavascriptModuleObject();
        ?>
        <style type="text/css">
            .extrnd-init-hidden { display: none; }
        </style>
        <i id="extrnd-icon-template" class="fas fa-cube fs14 ml-2 extrnd-init-hidden" data-bs-toggle="tooltip" aria-label="Extended Randomisation" data-bs-original-title="Extended Randomisation"></i>
        <script type="text/javascript">
            let module = <?=$this->getJavascriptModuleObjectName()?>;
            module.extrndIcons = JSON.parse('<?=\js_escape(\json_encode($extrndIcons))?>');
            module.iconTemplate = $('#extrnd-icon-template');
            module.makeIcon = function(rndAttr) {
                console.log(rndAttr);
                let label = 'Extended Randomisation: '+rndAttr.label;
                let thisIcon = $(module.iconTemplate).clone().removeAttr('id');
                let tblRow = $('#RandSummaryTable').find('tr:nth-of-type('+(1+rndAttr.rowIndex)+')'); // +1 cos row 0 is 1st of type etc
                let tblCell = $(tblRow).find('td:nth-of-type(3)');
                $(thisIcon)
                    .attr('aria-label',label)
                    .attr('data-bs-original-title',label)
                    .appendTo(tblCell)
                    .tooltip()
                    .removeClass('extrnd-init-hidden');
            };
            module.init = function() {
                module.extrndIcons.forEach((element) => module.makeIcon(element));
            };
            $(document).ready(function(){
                module.init();
            });
        </script>   
        <?php
    }

    /**
     * setupPage()
     * Additional content for Randomisation Setup page
     * - Step 5: randomiser options
     */
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
            list($extRndOpts, $extRndOptsConfig) = $this->setupPageContent($rid);

            list($thisRidKey, $thisClass, $thisRandConfigText) = $this->getRandConfigSettings($rid);
            $extRndOpt = (empty($thisClass)) ? self::REDCAP_DEFAULT : $thisClass;
            $ridConfig = \json_decode($thisRandConfigText, true) ?? array();
            $classname = __NAMESPACE__.'\\'.$extRndOpt;
            $randomiser = new $classname($rid, $this, false);
            $configEditable = $randomiser->isConfigEditable();
            $hasEditableSettings = (count($randomiser::$ProdEditableSettings) > 0);
        }

        $saveButton = \RCView::button(array('id'=>'extrnd-opt-save','class'=>'btn btn-xs btn-defaultrc'), '<i class="fas fa-cog mr-1"></i>'.\RCView::tt('control_center_4878'));

        if ($configEditable) {
            $selectRandomiser = \RCView::select(array('class'=>'mx-2','style'=>'max-width:400px;', 'id'=>'extrnd-opt', 'name'=>'extrnd-opt', $disabled=>''), $extRndOpts, $extRndOpt);
        } else {
            // e.g. in prod - can't change the randomiser once > 0 records randomised!
            $prodRndOpt = array($extRndOpt => $extRndOpts[$extRndOpt]);
            $selectRandomiser = \RCView::select(array('class'=>'mx-2','style'=>'max-width:400px;', 'id'=>'extrnd-opt', 'name'=>'extrnd-opt', 'disabled'=>'disabled'), $prodRndOpt, $extRndOpt);
            
            if (!$hasEditableSettings) {
                $saveButton = \RCView::span(array('class'=>'text-muted text-danger'), 'Not editable: records randomized in Production');
            }
        }

        print \RCView::div(array('id'=>'extrnd-step5div','class'=>'round chklist extrnd-init-hidden','style'=>'background-color:#eee;border:1px solid #ccc;padding:5px 15px 15px;'.$opacity),
            \RCView::p(array('style'=>'color:#800000;font-weight:bold;font-size:13px;'), \RCView::span(array(),'STEP 5: <i class="fas fa-cube mr-1"></i>Extended Randomization Options')) .
            \RCView::p(array('style'=>''), \RCView::span(array(),'Additional configuration options provided by the <em><i class="fas fa-cube mr-1"></i>Extended Randomization</em> external module.')) .
            \RCView::div(array(),
                \RCView::span(array('class'=>'font-weight-bold', 'style'=>'display:inline-block;min-width:120px;'), \RCView::span(array(),$envelope."Randomization option")) . 
                $selectRandomiser .
                $saveButton . 
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
            module.randomiserConfig = JSON.parse('<?=\js_escape2(\json_encode($ridConfig, JSON_FORCE_OBJECT))?>');
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
                $('#extrnd-opt-config-'+module.randomiser).find('input,select').each(function(i,e){
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
                        setTimeout(function(){
                            $('#extrnd-opt-savedmsg').fadeOut(1000).html('');
                        }, 5000);
                    } else {
                        $('#extrnd-opt-savedmsg').addClass('text-danger').html('<i class="fas fa-exclamation-triangle mr-1"></i>'+data.message).show();
                        $('#extrnd-opt-save').prop('disabled', false);
                        console.log(data.message);
                    }
                });

            };
            module.init = function() {
                module.setRandomiserSettings();
                $('#extrnd-opt').on('change', module.selectRandomiser).trigger('change');
                $('#extrnd-opt-config-'+module.randomiser).find('input,select').on('change', module.configEdited);
                $('#extrnd-opt-save').prop('disabled', true).on('click', module.saveRandomiserConfig);
                $('#extrnd-step5div').insertAfter('#step4div').show();
                const popoverTriggerList = $('a.extrnd-auto-extend-info[data-bs-toggle="popover"]');
                const popoverList = [...popoverTriggerList].map(triggerEl => new bootstrap.Popover(triggerEl, {
                        html: true,
                        sanitize: false,
                        trigger: 'focus'
                    }));
            };
            $(document).ready(function(){
                module.init();
            });
        </script>   
        <?php
    }

    /**
     * setupPageContent()
     * Config page content 
     *  - select list of applicable randomiser classes
     *  - divs with info and config setting fields for applicable randomiser classes
     */
    public function setupPageContent($rid) { 
        $currentSettings = array();
        $attrs = $this->getRandAttrs($rid);

        // get any existing config
        list($thisRidKey, $randomiserName, $thisRandConfigText) = $this->getRandConfigSettings($rid);
        if (!empty($randomiserName) && $randomiserName!=='Default' && !empty($thisRandConfigText)) {
            $currentSettings = \json_decode($thisRandConfigText, true) ?? array();
            if (!is_array($currentSettings)) $currentSettings = array();
        }

        $availableOptions = array();
        $configMarkup = '';
        
        foreach(AbstractRandomiser::getRandomiserNames() as $thisRandomiserName) {
            $classname = __NAMESPACE__.'\\'.$thisRandomiserName;
            $randomiser = new $classname($rid, $this, false);
            try {
                $randomiser->applySavedConfig();
            } catch (\Throwable $th) {
                $configErrorMessage = $th->getMessage();
            }
            if ( ( $attrs['isBlinded'] && $classname::USE_WITH_CONCEALED) ||
                 (!$attrs['isBlinded'] && $classname::USE_WITH_OPEN) )  {
                $availableOptions[$randomiser->getConfigOptionName()] = $randomiser->getConfigOptionLabel();
                $extendOption = $randomiser->getExtendAllocationTableOption();
                $configMarkup .= $randomiser->getConfigOptionMarkup( $extendOption );
            }
        }
        return array($availableOptions, $configMarkup);
    }

    /**
     * dashboardPage()
     * Add tab for Batch page when enabled and user has dashboard and perform rights
     */
    public function dashboardPage($rid): void {
        global $user_rights,$status;
        if (!($user_rights['random_dashboard']=='1' && $user_rights['random_perform']=='1')) return;
        switch ($status) {
            case '0': $batchEnabled = true; break; // $this->getProjectSetting('enable-batch-dev'); break;
            case '1': $batchEnabled = $this->getProjectSetting('enable-batch-prod'); break;
            default: $batchEnabled = false; break;
        }
        if (!$batchEnabled && !static::SHOW_DISABLED_BATCH_TAB) return;

        $url = $this->getUrl('batch.php',false,false).'&rid='.intval($rid);
        ?>
        <style type="text/css">
            .extrnd-tab-diabled { opacity: 50%; }
        </style>
        <script type="text/javascript">
            $(document).ready(function() {
                const message = 'Administrator must enable for Production status <br>in the \"Extended Randomization\" external module settings.';
                const enabled = <?=($batchEnabled)?'true':'false';?>;
                let tab = $('#sub-nav li:last').clone();
                $(tab).removeClass('active').find('span:first').html('Batch Randomisation');
                if (enabled) {
                    $(tab).find('a:first').attr('href', '<?=$url?>');
                } else {
                    let a = $(tab).find('a:first');
                    $(a).attr('href','javascript:;').on('click', function(){
                        simpleDialog(message, 'Batch Randomisation');
                    });
                    $(tab).addClass('extrnd-tab-diabled').attr('title', message.replace(/<[^>]*>/g, ''));
                }
                $(tab).appendTo('#sub-nav ul');
            });
        </script>   
        <?php
    }

    /**
     * batchRandomisationPage()
     * Module page for performing multiple sequential randomisations 
     */
    public function batchRandomisationPage($rid): void {
        global $Proj,$longitudinal,$status,$user_rights,$lang;

        if (!($user_rights['random_dashboard']=='1' && $user_rights['random_perform']=='1')) {
            print  "<div class='red'><img src='" . APP_PATH_IMAGES . "exclamation.png'> <b>{$lang['global_05']}</b><br><br>{$lang['config_02']} {$lang['config_06']}</div>";
            return;
        }

        echo '<div class="projhdr"><i class="fas fa-random"></i> Randomization</div>';
        echo '<p></p>';
        $this->renderTabs($rid);

        $randAttr = $this->getRandAttrs($rid);

        $statusLabel = \RCView::tt('edit_project_58','span',array('style'=>'font-weight-bold;color:#000;float:none;border:0;')).'&nbsp; ';
        // Set icon/text for project status
        if ($status == '0') {
            print '<div class="mb-2"><span style="color:#666;font-weight:normal;">'.$statusLabel.'<i class="ms-1 fas fa-wrench"></i> '.\RCView::tt('global_29').'</span></div>';
        } elseif ($status == '1') {
            print '<div class="mb-2"><span style="color:#00A000;font-weight:normal;">'.$statusLabel.'<i class="ms-1 far fa-check-square"></i> '.\RCView::tt('global_30').'</span></div>';
        } else {
            print '<div class="red"><span style="color:#A00000;font-weight:normal;">'.$statusLabel.'<i class="">ms-1 fas fa-minus-circle</i> '.\RCView::tt('global_159').'</span></div>';
            return;
        }

        if (empty($randAttr)) {
            print  "<div class='red'><img src='" . APP_PATH_IMAGES . "exclamation.png'> <b>{$lang['random_155']}</b></div>";
            return;
        }

        if ($longitudinal) {
            $targetDiv = \RCView::div(array('class'=>'my-1'), 
                \RCView::tt('random_170').\RCView::tt('colon'). ' '.
                \RCView::span(array('class'=>'font-weight-bold'), $Proj->eventInfo[$randAttr['targetEvent']]['name_ext'])
            );
        }

        $targetField = $randAttr['targetField'];
        $targetFieldLabel = $this->escape(strip_tags($Proj->metadata[$targetField]['element_label']));
        if (mb_strlen($targetFieldLabel) > 40) $label = mb_substr($targetFieldLabel, 0, 38) . "...";
        $targetDiv .= \RCView::div(array('class'=>'my-1'), 
            \RCView::tt('random_171').\RCView::tt('colon'). ' '.
            \RCView::span(array('class'=>'font-weight-bold'), $targetFieldLabel).
            \RCView::span(array('class'=>'ml-1 badge badge-primary'), $targetField)
        );
        
        if ($randAttr['isBlinded']) {
            $targetDiv .= \RCView::div(array('class'=>'font-weight-normal gray'), '<i class="fas fa-envelope text-danger mr-1"></i>'.\RCView::tt('random_138'));
        } else {
            $targetDiv .= \RCView::div(array('class'=>'font-weight-normal gray'), '<i class="fas fa-envelope-open text-success mr-1"></i>'.\RCView::tt('random_137'));
        }

        // Get the enum choices for all stratification fields
        $randomizationEnums = array();
        $stratDisplay = '';
        foreach (array_keys($randAttr['strata']) as $rfld) {
            $randomizationEnums[$rfld] = array();
            if (isset($Proj->metadata[$rfld])) {
                $stratFieldLabel = $this->escape(strip_tags($Proj->metadata[$rfld]['element_label']));
                if (mb_strlen($stratFieldLabel) > 40) $stratFieldLabel = mb_substr($stratFieldLabel, 0, 38) . "...";
        
                $stratDisplay .= "<li>$stratFieldLabel".\RCView::span(array('class'=>'mx-1 badge badge-secondary'), $rfld).\RCView::tt('colon', false);
                foreach ($this->getChoiceLabels($rfld) as $key => $lbl) {
                    $lbl = $this->formatLabel($lbl);
                    $randomizationEnums[$rfld][$key] = $lbl;
                    $stratDisplay .= \RCView::span(array('class'=>'text-muted')," $lbl ($key);");
                }
                $stratDisplay .= "</li>";
            }
        }
        // If grouping by DAG, then add DAG to rand field enums
        if ($randAttr['group_by'] == 'DAG') {
            $randomizationEnums['group_id'] = \REDCap::getGroupNames(true, $user_rights['group_id']);
            $stratDisplay .= "<li>";
            $stratDisplay .= \RCView::tt('global_78').\RCView::span(array('class'=>'mx-1 badge badge-secondary'), 'redcap_data_access_group').\RCView::tt('colon', false).' ';
            $stratDisplay .= \RCView::span(array('class'=>'text-muted'),implode('; ',array_values(\REDCap::getGroupNames(false, $user_rights['group_id']))));
            $stratDisplay .= "</li>";
        }

        // Display target and strata (if applicable)
        $targetBSCol = (sizeof($randomizationEnums)) ? 6 : 12;
        echo "<div id='extrnd-infocontainer' class='container'><div class='row'><div class='col-md-$targetBSCol'>";
        echo \RCView::h5(array('class'=>'mt-2','style'=>'color:#800000'),\RCView::tt('random_139')); // Randomization Field
        echo \RCView::div(array('class'=>'px-4'), $targetDiv);
        echo '</div>';

        if (sizeof($randomizationEnums)) {
            echo '<div class="col-md-6">';
            echo \RCView::h5(array('class'=>'mt-4','style'=>'color:#800000'),\RCView::tt('random_157')); // Stratification
            echo \RCView::ul(array(),$stratDisplay);
            echo '</div>';
        }
        echo '</div></div>';

        echo \RCView::h5(array('class'=>'mt-4','style'=>'color:#800000'),'Randomize Records');
        echo \RCView::p(array(),'The table below shows records in the project (filtered by DAG where applicable) that are ready to be randomized.');
        echo \RCView::p(array(),'Randomize them individually or in batches by selecting the checkboxes and clicking "Randomize".');

        // if not enabled in prod then show message rather than table of records
        switch ($status) {
            case '0': $batchEnabled = true; break; // always enabled // $this->getProjectSetting('enable-batch-dev'); break;
            case '1': $batchEnabled = $this->getProjectSetting('enable-batch-prod'); break;
            default: $batchEnabled = false; break;
        }
        if (!$batchEnabled) {
            echo \RCView::div(array('class'=>'yellow text-align-center'), 'Batch randomization is not enabled.');
            return;
        }

        $recordTable = $this->getBatchRecordTable($rid, $_GET['sort']);

        list($progressTable, $charts) = $this->getRandCharts($rid);

        $sortFields = array();
        foreach ($Proj->metadata as $fldName => $fldAttr) {
            if ($fldAttr['element_type']!=='text') continue;
            $sortFields[$fldName] = $fldAttr['element_label'];
        }

        echo '<div id="extrnd-batchcontainer" class="container"><div class="row"><div class="col-md-6"><h6 style="color:#800000;">Records</h6>';
        echo \RCView::p(array(),
            \RCView::span(array('class'=>'font-weight-bold'), 'Sort by: ').
            \RCView::select(array('id'=>'extrnd-batchsort'), $sortFields, $_GET['sort'])
        );
        echo "$recordTable</div><div class='col-md-6'><h6 style='color:#800000;'>Progress</h6>$progressTable</div></div></div>";

        $this->initializeJavascriptModuleObject();
        ?>
        <style type="text/css">
            #extrnd-infocontainer  { width:950px;max-width:950px;margin:0;padding:0; }
            #extrnd-batchcontainer { width:950px;max-width:950px;margin:0; }
            .dataTables_filter { float: left !important; }
            .extrnd-chart { border-top: solid 2px #bbb; }
        </style>
        <script type="text/javascript">
            let module = <?=$this->getJavascriptModuleObjectName()?>;
            module.rid = <?=$rid?>;
            module.records = [];
            module.chartDefs = JSON.parse('<?= \json_encode($charts)?>');
            module.chartObjs = [];

            module.changeSort = function() {
                var params = new URLSearchParams(location.search);
                params.set('sort', $(this).val());
                window.location.search = params.toString();
            };

            module.toggleSelectAll = function() {
                $('input.extrnd-batchrec').prop('checked', $(this).is(':checked'));
            };

            module.comfirmBatch = function() {
                let checked = $('input.extrnd-batchrec:checked');
                if (checked.length === 0) {
                    alert('No records selected');
                    return;
                }
                module.records = [];
                $(checked).each(function(i,e){
                    module.records.push($(e).data('record'));
                });

                simpleDialog(
                    'Randomize '+checked.length+' record'+((checked.length>1)?'s':'')+'?',
                    'Batch Randomization',
                    null,300,null,lang.global_53,
                    module.randomiseBatch
                );
            };

            module.randomiseBatch = function() {
                $('#extrnd-batchgo').prop('disabled', true)
                module.records.forEach(function(r) {
                    module.batchCounter = 0;
                    $('input[data-record='+r+']').prop(':checked',false).remove();
                    $('td[data-record='+r+']').html('<div class="spinner-border spinner-border-sm" role="status"><span class="visually-hidden">Loading</span></div>');
                    module.ajax('randomise-record', { rid: module.rid, rec: r }).then(function(data) {
                        let recordOutput;
                        if (!data.hasOwnProperty('result')) data.result = 0;
                        if (!data.hasOwnProperty('message')) data.message = window.woops;
                        if (data.result) {
                            recordOutput = '<span class="text-success"><i class="fas fa-check-circle mr-1"></i>'+data.message+'</span>';
                            if (data.hasOwnProperty('allocation') && data.allocation.hasOwnProperty('group_index')) {
                                let group_index = data.allocation.group_index;
                                module.updateChart('all', data.allocation.group_index);
                                if (data.allocation.stratum_index!==null) {
                                    module.updateChart('strat'+data.allocation.stratum_index, data.allocation.group_index);
                                }
                            }
                        } else {
                            recordOutput = '<span class="text-danger"><i class="fas fa-times-circle mr-1"></i>'+data.message+'</span>';
                        }
                        $('td[data-record='+r+']').html(recordOutput);
                        module.batchCounter++
                        if (module.batchCounter>=module.records.length && $('input.extrnd-batchrec').length) {
                            $('#extrnd-batchgo').prop('disabled', false); // at end of batch, make button available again if more can be done
                        }
                    });
                });
            };

            module.renderChart = function(chart) {
                var id = 'extrnd-chart-'+chart.ref;
                var context = document.getElementById(id).getContext('2d');
                var datasets = [];
                datasets.push({ 
                    label: 'Number Randomized',
                    data: chart.group_counts, 
                    backgroundColor: window.chartColors[0], pointRadius: 3, pointHoverRadius: 6, fill: false, borderColor: window.chartColors[0], spanGaps: true
                });
                
                var scales = { 
                    xAxes: [{ stacked: false, ticks: {beginAtZero:true}, scaleLabel: '' }], 
                    yAxes: [{ stacked: false, ticks: {beginAtZero:true}, scaleLabel: '', display: true }] 
                };
                var plugins = { labels: false };
                var type = 'horizontalBar';
                var options = { responsive: true, legend: { display: true }, scales: scales, animation: { duration: 0 }, plugins: plugins };

                var thisChartConfig = {
                    type: type,
                    data: { labels: chart.group_labels, datasets: datasets },
                    options: options
                };
                var thisChart = new Chart(context, thisChartConfig);
                module.chartObjs.push([chart.ref,thisChartConfig]);
                document.getElementById(id).style.backgroundColor = '#FFFFFF'; // white bg color
            };

            module.updateChart = function(chartRef, group_index) {
                try {

                    console.log(chartRef);
                    console.log(group_index);
                    console.log(module.chartObjs);

                    module.chartObjs.forEach(e => {
                        if (e[0]==chartRef) {
                            var id = 'extrnd-chart-'+chartRef;
                            var chartContext = document.getElementById(id).getContext('2d');
                            var thisChart = e[1];
                            thisChart.data.datasets[0].data[group_index]++;
                            var chart = new Chart(chartContext, thisChart);
                            chart.update();
                        }
                    });

                } catch (e) {
                    console.error(e);
                }
            };

            module.init = function() {
                $('#extrnd-batchsort').on('change', module.changeSort);
                $('#extrnd-batchall').on('change', module.toggleSelectAll);
                $('#extrnd-batchgo').on('click', module.comfirmBatch);
                $('#extrnd-batchtable').DataTable({
                    paging: false,
                    ordering: false,
                    layout: {
                        topStart: 'search',
                        topEnd: null,
                        bottomStart: 'info',
                        bottomEnd: null
                    }
                });
                module.chartDefs.forEach(chart => {
                    console.log(chart);
                    module.renderChart(chart);

                })
            };
            $(document).ready(function(){
                module.init();
            });
        </script>
        <?php
    }

    /**
     * getBatchRecordTable()
     * @param $sortField
     * @return string
     */
    protected function getBatchRecordTable($rid, $sortField=''): string {
        global $Proj, $user_rights;
        $pk = $Proj->table_pk;
        if ($sortField==$pk) $sortField = '';

        $randAttrs = \Randomization::getRandomizationAttributes($rid);
        $stratified = (sizeof($randAttrs['strata']) || $randAttrs['group_by']=='DAG');

        $filterLogic = $this->buildUnrandomisedLogicExpr($rid);
        if (!empty($user_rights['group_id'])) {
            $userDag = \REDCap::getGroupNames(true, $user_rights['group_id']);
            $filterLogic = "[record-dag-name]='$userDag' and ($filterLogic)";
        }

        $filteredData = \REDCap::getData(array(
            'return_format' => 'json-array',
            'fields' => array($pk, $sortField),
            'exportDataAccessGroups' => true,
            'filterLogic' => $filterLogic
        ));

        $recordsToRandomise = array();
        foreach ($filteredData as $rec) {
            $recordsToRandomise[$rec[$pk]] = ($sortField=='') ? '' : $rec[$sortField];
        }
        natcasesort($recordsToRandomise); // natural sort by value, preserve keys

        $targetArm = $Proj->eventInfo[$randAttrs['targetEvent']]['arm_num'];
        $crlByArm = \Records::getCustomRecordLabelsSecondaryFieldAllRecords(array_keys($recordsToRandomise), true, $targetArm);
        
        if ($stratified) {
            $dags = \REDCap::getGroupNames(true);
            $recordStrata = array();
            $fields = array_keys($randAttrs['strata']);
            $fields[] = $pk;
            $stratumData = \REDCap::getData(array(
                'return_format' => 'array',
                'records' => array_keys($recordsToRandomise),
                'fields' => $fields,
                'exportDataAccessGroups' => true
            ));

            foreach(array_keys($stratumData) as $recId) {
                $recStrat = array();
                $fields = array();
                $group_id = null;

                foreach($randAttrs['strata'] as $stratField => $stratEvent) {
                    $stratValue = $stratumData[$recId][$stratEvent][$stratField] ?? '';
                    $recStrat[] = '<span class="badge bg-secondary">['.$stratField.'] = "'.$this->escape($stratValue).'"</span>';
                    $fields[$stratField] = $stratValue;
                }
                if ($randAttrs['group_by']=='DAG') {
                    $evt = key($stratumData[$recId]);
                    $stratValue = $stratumData[$recId][$evt]['redcap_data_access_group'] ?? '';
                    $recStrat[] = '<span class="badge bg-secondary">[record-dag-name] = "'.$this->escape($stratValue).'"</span>';
                    $group_id = intval(array_search($stratValue, $dags));
                }
                $stratIdx = $this->getStratumIndex($rid, $fields, $group_id);
                $recordStrata[$recId] = "<div class='extrnd-stratum' data-stratIndex='$stratIdx'>".implode('<br>', $recStrat)."</div>";
            }
        }


        $tbl = '<table id="extrnd-batchtable"><thead><tr>';
        $tbl .= '<th>'.$this->escape(strip_tags($Proj->metadata[$pk]['element_label'])).'</th>';
        
        if ($stratified) {
            $tbl .= '<th>'.\RCView::tt('random_169').'</th>';
        }
        
        $tbl .= '<th><input id="extrnd-batchall" type="checkbox">';
        $tbl .= '<button id="extrnd-batchgo" class="btn btn-xs btn-success mx-2"><i class="fas fa-random mr-1"></i>Randomize</button></th>';
        $tbl .= '</tr></thead><tbody>';

        foreach ($recordsToRandomise as $rec => $sortVal) {
            $rec = $this->escape($rec);
            $sortVal = $this->escape($sortVal);
            $tbl .= "<tr><td>$rec ";
            if ($crlByArm[$rec]!=='') $tbl .= \RCView::span(array('class'=>'crl'), $crlByArm[$rec]);
            if ($sortVal!=='') $tbl .= \RCView::span(array('class'=>'text-muted'), " ($sortVal)");
            $tbl .= '</td>';

            if ($stratified) {
                $tbl .= '<td>'.$recordStrata[$rec].'</td>';
            }
            
            $tbl .= "<td data-record='$rec'><input class='extrnd-batchrec' data-record='$rec' type='checkbox'></td></tr>";
        }
        return $tbl.'</tbody></table>';
    }

    /**
     * getRandCharts()
     * Get the display markup and chart objects for the current randomisation
     */
    protected function getRandCharts($rid) {
        global $Proj;
        $ra = $this->getRandAttrs($rid);

        $charts = $this->getStrataCharts($rid);

        // read allocation table for this rand
        $sql = "select a.*
                from redcap_randomization_allocation a
                inner join redcap_randomization r on r.rid = a.rid
                where r.project_id = ? and r.rid = ? and a.project_status = ? ";
        $params = array($Proj->project_id, $rid, $Proj->project['status']);
        $q = $this->query($sql, $params);
        while ($row = db_fetch_assoc($q))
        {
            if (!is_null($row['is_used_by'])) {
                $grp = ($ra['isBlinded']) ? 0 : $row['target_field'];
                $grpIndex = array_search($grp, array_keys($ra['groups']));

                $thisEntryStrat = array();
                for ($i=1; $i < 16; $i++) { 
                    if (!is_null($row["source_field$i"])) {
                        $thisEntryStrat[] = "source_field$i-".$row["source_field$i"];
                    }
                }
                if (!is_null($row["group_id"])) {
                    $thisEntryStrat[] = "group_by-".$row["group_id"];
                }
                $stratumCombo = implode('&',$thisEntryStrat);
                $stratumIndex = array_search($stratumCombo, $ra['strataSourceCombos']);

                foreach ($charts as $chart) {
                    if ($chart->ref == 'all' || $chart->ref == "strat$stratumIndex") {
                        $chart->group_counts[$grpIndex]++;
                    }
                }
            }
        }

        $html = '<div id="extrnd-rand-charts">';

        foreach ($charts as $thisChart) {
            $html .= '<div id="extrnd-chart-div-'.$thisChart->ref.'" class="extrnd-chart">';
            $html .= '<p>'.\REDCap::filterHtml($thisChart->title).'</p>';
            $html .= '<canvas id="extrnd-chart-'.$thisChart->ref.'"></canvas>';
            $html .= '</div>';
            unset($thisChart->title);
        }
        $html .= '</div>';
        return array($html, $charts);
    }

    /**
     * getStrataCharts()
     * Make a chart object for overall + 1 per stratum
     */
    protected function getStrataCharts($rid): array {
        $ra = $this->getRandAttrs($rid);
        $groups = $ra['groups'];

        $stdChart = new \stdClass();
        $stdChart->ref = '';
        $stdChart->title = '';
        $stdChart->group_values = array_keys($groups);
        $stdChart->group_labels = array_values($groups);
        $stdChart->group_counts = array_fill(0, sizeof($groups), 0);
        
        $chartAll = clone $stdChart;
        $chartAll->ref = 'all';
        $chartAll->title = '<b>Overall</b>';

        $charts = array($chartAll);
        foreach ($ra['strataSourceCombos'] as $i => $comboString) {
            $stratChart = clone $stdChart;
            $stratChart->ref = "strat$i";
            $comboTitle = '';
            $comboExpr = '';
            foreach(explode('&',$comboString) as $cs) {
                list($comboSource, $comboSourceLevel) = explode('-', $cs, 2);  // e.g. source_field1-1 -> [source_field1, 1]
                $found = false;
                foreach($ra['stratFields'] as $sf) {
                    $sfSourceField = 'source_field'.$sf['source_field'];
                    $sfFieldName = $sf['field_name'];
                    if ($sfSourceField ==  $comboSource || ($sfFieldName=='redcap_data_access_group' && $comboSource=='group_by')) {
                        foreach($sf['levels'] as $lvl => $lvlLbl) {
                            if ($lvl == $comboSourceLevel) {
                                $found = true;
                                $stratFldLabel = $this->formatLabel($sf['field_label']);
                                $stratLvlLabel = $this->formatLabel($lvlLbl);
                                if ($sfFieldName=='redcap_data_access_group') {
                                    $lvl = \REDCap::getGroupNames(true, $lvl); // convert id to unique name for expr
                                    $comboExpr .= "[record-dag-name] = '$lvl' and ";
                                } else {
                                    $comboExpr .= "[{$sfFieldName}] = '$lvl' and ";
                                }
                                $comboTitle .= '<span class="badge bg-secondary mr-2">['.$sfFieldName.'] = "'.$lvl.'"</span>'.\RCView::b($stratFldLabel).': '.$stratLvlLabel.'<br>';
                                break;
                            }
                        }
                    }
                    if ($found) break;
                }
            }

            $comboExpr = '<code>'.trim($comboExpr, ' and ').'</code>';
            $stratChart->title = $comboTitle.$comboExpr;
            $charts[] = $stratChart;
        }

        return $charts;
    }

    /**
     * renderTabs()
     * Render the tabs at top of Batch Randomization page based upon user rights
     */
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
    //endregion

    //region Helper methods

    /**
     * getRandAttrs($rid)
     * Enhanced version of \Randomization::getRandomizationAttributes($rid) also capturing allocation groups and unique strata combinations
     */
    public function getRandAttrs($rid) {
        if (!isset($this->randAttrs) || $rid !== $this->randAttrs['randomization_id']) {
            global $Proj;
            $this->randAttrs = \Randomization::getRandomizationAttributes($rid);
            if ($this->randAttrs===false) return false;

            if ($this->randAttrs['isBlinded']) {
                $this->randAttrs['groups'] = array(0=>'Total');
            } else {
                $this->randAttrs['groups'] = $this->getChoiceLabels($this->randAttrs['targetField']);
            }
            
            $stratFields = array();
            foreach(array_keys($this->randAttrs['strata']) as $sIdx => $s) {
                $stratFields[] = array(
                    'source_field' => $sIdx+1,
                    'field_name' => $s,
                    'field_label' => $this->formatLabel($Proj->metadata[$s]['element_label']),
                    'levels' => $this->getChoiceLabels($s)
                );
            }
            if ($this->randAttrs['group_by']=='DAG') {
                $stratFields[] = array(
                    'source_field' => 'group_by',
                    'field_name' => 'redcap_data_access_group',
                    'field_label' => \RCView::tt('global_78', false),
                    'levels' => \REDCap::getGroupNames()
                );
            }
            $this->randAttrs['stratFields'] = $stratFields;
            $this->randAttrs['strataSourceCombos'] = $this->makeStrataSourceCombos($stratFields);
        }
        return $this->randAttrs;
    }

    /**
     * getStratumIndex()
     * Get the stratum index for the provided set of stratfication values
     */
    public function getStratumIndex($rid, array $fields, ?int $group_id) : ?int {
        $ra = $this->getRandAttrs($rid);
        if (empty($fields) && empty($group_id)) return null;
        $parts = array();
        foreach (array_keys($fields) as $i => $sf) {
            $parts[] = "source_field".($i+1)."-".$fields[$sf];
        }
        if (!empty($group_id)) {
            $parts[] = "group_by-$group_id";
        }
        $stratumComboString = implode('&', $parts);
        $idx = array_search($stratumComboString, $ra['strataSourceCombos']);
        return ($idx===false) ? null : $idx;
    }

    /**
     * makeStrataSourceCombos()
     * Generate unique stratum codes in terms of their source fields in the allocation table
     */
    protected function makeStrataSourceCombos($stratFields) : array {
        $levels = array();
        foreach($stratFields as $i => $s) {
            foreach(array_keys($s['levels']) as $l) {
                if ($s['source_field']=='group_by') {
                    $levels[$i][] = 'group_by-'.$l;
                } else {
                    $levels[$i][] = 'source_field'.$s['source_field'].'-'.$l;
                }
            }
        }

        $comboArrays = \Randomization::getCombinations($levels);
        $comboStrings = array();
        foreach ($comboArrays as $c) {
            $comboStrings[] = (is_array($c)) ? implode('&',$c) : $c;
        }
        /*
        source_field1-1a&source_field2-2a&group_id-123
        source_field1-1a&source_field2-2a&group_id-124
        source_field1-1a&source_field2-2b&group_id-123
        source_field1-1a&source_field2-2b&group_id-124
        source_field1-1b&source_field2-2a&group_id-123
        source_field1-1b&source_field2-2a&group_id-124
        source_field1-1b&source_field2-2b&group_id-123
        source_field1-1b&source_field2-2b&group_id-124
        */
        return $comboStrings;
    }

    /**
     * makeRandomiser()
     * Obtain an instance of the appropriate randomiser class for this randomisation
     */
    protected function makeRandomiser($randomization_id, bool $applyConfig=true): ?AbstractRandomiser {
        list($thisRidKey, $randomiserType, $thisRandConfigText) = $this->getRandConfigSettings($randomization_id);

        if (is_null($thisRidKey)) return null; // no EM config this rand
        
        if (file_exists(dirname(__FILE__)."/Randomisers/$randomiserType.php")) {
            require_once "Randomisers/$randomiserType.php";
            $classname = __NAMESPACE__."\\$randomiserType";
        } else {
            throw new \Exception("Could not read module config for randomization id $randomization_id");
        }

        $randomiser = new $classname($randomization_id, $this, $applyConfig);

        return $randomiser;
    }

    /**
     * getRandConfigSettings
     * Read the module config settings for the specified randomisation
     * @param $rid The unique randomisation id to read the module settings for
     * @return array (int key in config array or false if not yet present; string randomiser class name; string config text or empty; bool alloctable extend option)
     */
    public function getRandConfigSettings($rid) : array {
        $randConfig = $this->getSubSettings('project-rand-config');
        $rtnKey = null;
        $rtnClass = '';
        $rtnText = '';
        $rtnExtend = false;
        foreach($randConfig as $key => $randSettings) {
            $thisRid = $randSettings['rand-id'];
            if ($rid==$thisRid) {
                $rtnKey = $key;
                $rtnClass = $randSettings['rand-class'];
                $rtnText = $randSettings['rand-config'];
                $rtnExtend = (bool)$randSettings['rand-extend-table'];
                break;
            }
        }
        return array($rtnKey,$rtnClass,$rtnText,$rtnExtend);
    }

    /**
     * getRandStateSettings
     * Read the module config settings for the specified randomisation's stored state
     * @param $rid The unique randomisation id to read the module settings for
     * @return string the stored state as text
     */
    public function getRandStateSettings($rid) : string {
        $randStateConfig = $this->getSubSettings('project-rand-config');
        $rtnStateText = '';
        foreach($randStateConfig as $key => $randSettings) {
            $thisRid = $randSettings['rand-id'];
            if ($rid==$thisRid) {
                if ($key!=$this->config_current_index) throw new \Exception("Unexpected index for randomisaation state"); // paranoid double check
                $rtnStateText = $randSettings['rand-state'] ?? '';
                break;
            }
        }
        return $rtnStateText;
    }

    /**
     * buildUnrandomisedLogicExpr()
     * Construct a filter logic expression to pick up records that are ready to randomise 
     * (Not randomised already and have all necessary stratification)
     */
    protected function buildUnrandomisedLogicExpr($rid): string {
        global $longitudinal;
        $ra = \Randomization::getRandomizationAttributes($rid);
        $expr = ($longitudinal) ? '['.\REDCap::getEventNames(true, false, $ra['targetEvent']).']' : '';
        $expr .= '['.$ra['targetField'].']=""';
        if (sizeof($ra['strata']) > 0) {
            foreach($ra['strata'] as $fld => $evt) {
                $expr .= ' and ';
                $expr .= ($longitudinal) ? '['.\REDCap::getEventNames(true, false, $evt).']' : '';
                $expr .= '['.$fld.']<>""';
            }
        }
        if ($ra['group_by'] == 'DAG') {
            $expr .= ' and [record-dag-name]<>""';
        }
        return $expr;
    }

    /**
     * ajaxSaveConfig()
     * - Save module config for specific randomisation on Setup page
     */
    public function ajaxSaveConfig($payload, $project_id) {
        $result = false;
        $message = '';
        try {
            if (!is_array($payload)) throw new \Exception('Unexpected payload');
            if (array_key_exists('rid', $payload)) {
                $rid = $payload['rid'];
                unset($payload['rid']);
            } else {
                throw new \Exception('Unknown randomization');
            }
            $randAttrs = $this->getRandAttrs($rid);
            if ($randAttrs===false) throw new \Exception('Unknown randomization');

            if (!array_key_exists('randomiser_class', $payload)) throw new \Exception('Unknown randomization class');
            $randomiserName = $payload['randomiser_class'];
            unset($payload['randomiser_class']);

            if (file_exists(dirname(__FILE__)."/Randomisers/$randomiserName.php")) {
                require_once "Randomisers/$randomiserName.php";
                $classname = __NAMESPACE__.'\\'.$randomiserName;
            } else {
                throw new \Exception("Unknown randomization class");
            }
    
            $randomiser = new $classname($rid, $this, false);

            if ($randomiser->canExtendTable() 
                    && array_key_exists('extrnd-extend-table', $payload)
                    && $payload['extrnd-extend-table']=='1') {
                $extendTable = true;
                unset($payload['extrnd-extend-table']); // remove before validate() because now validated
            } else {
                $extendTable = false;
            }

            $validate = $randomiser->validateConfigSettings($payload);
            if ($validate!==true) {
                throw new \Exception($validate);
            }
            
            $project_settings = $this->getProjectSettings($project_id);

            list($thisRidKey, $thisClass, $thisRandConfigText) = $this->getRandConfigSettings($rid);
            
            $settingIndex = $thisRidKey;
            if (is_null($thisRidKey)) {
                if (is_null($project_settings['rand-id'][0])) {
                    $settingIndex = 0;
                } else {
                    $settingIndex = count($project_settings['rand-id']);
                }
            }

            if ($randomiser->isConfigEditable()) {
                $project_settings['project-rand-config'][$settingIndex] = 'true';
                $project_settings['rand-id'][$settingIndex] = "$rid";
                $project_settings['rand-class'][$settingIndex] = $randomiserName;
                $project_settings['rand-extend-table'][$settingIndex] = $extendTable;
                $project_settings['rand-config'][$settingIndex] = (empty($payload)) ? '' : \json_encode($payload, JSON_FORCE_OBJECT);
            } else if (!empty($payload) && count($randomiser::$ProdEditableSettings) > 0) {
                // allow changes to only these properties
                $settings = $project_settings['rand-config'][$settingIndex];
                foreach ($randomiser::$ProdEditableSettings as $editableSetting) {
                    $settings[$editableSetting] = $payload[$editableSetting];
                }
                $project_settings['rand-config'][$settingIndex] = $settings;
            } else {
                // no edits allowed (and save should not be possible, so something amiss)
                throw new \Exception('Config changes not permitted');
            }

            $this->setProjectSettings($project_settings, $project_id);
            $result = true;
            $message = 'Saved';
        } catch (\Throwable $th) {
            $message = $th->getMessage();
        }
        return array('result'=>$result, 'message'=>$message);
    }

    /**
     * ajaxRandomiseRecord()
     * - Randomise record from Batch page
     */
    public function ajaxRandomiseRecord($payload, $project_id) {
        global $user_rights;
        $result = false;
        $message = '';
        try {
            if (!($user_rights['random_dashboard']=='1' && $user_rights['random_perform']=='1')) throw new \Exception(\RCView::tt('global_05', false));
            if (!is_array($payload)) throw new \Exception('Unexpected payload');
            if (array_key_exists('rid', $payload)) {
                $rid = $payload['rid'];
                unset($payload['rid']);
            } else {
                throw new \Exception('Unknown randomization');
            }
            $randAttrs = $this->getRandAttrs($rid);
            if ($randAttrs===false) throw new \Exception('Unknown randomization');

            if (array_key_exists('rec', $payload)) {
                $record = $payload['rec'];
            } else {
                throw new \Exception('No record');
            }

            $already = \Randomization::wasRecordRandomized($record, $rid);
            if ($already) throw new \Exception(\RCView::tt('random_56', false));

            list($fields, $group_id, $missing) = \Randomization::readStratificationData($rid, $record);

            if ($randAttrs['group_by']=='DAG' && !empty($user_rights['group_id']) && $user_rights['group_id']!=$group_id) {
                throw new \Exception(\RCView::tt('global_05', false)); // access denied (record dag does not match user dag)
            }

            if (!empty($missing)) {
                throw new \Exception(\RCView::tt('global_01', false).' Missing '.implode(',',$missing));
            }
            
            list($result, $message, $target_value) = $this->getRandomizeRecordResult($rid, $record, $fields, $group_id);

            $allocation = array(
                'group' => ($randAttrs['isBlinded']) ? 0 : $target_value,
                'group_index' => ($randAttrs['isBlinded']) ? 0 : array_search($target_value, array_keys($randAttrs['groups'])),
                'stratum_index' => $this->getStratumIndex($rid, $fields, $group_id)
            );
        } catch (\Throwable $th) {
            $message = $th->getMessage();
            $allocation = null;
        }

        return array('result'=>$result, 'message'=>$message, 'allocation'=>$allocation);
    }

    /**
     * getRandomizeRecordResult()
     * Perform the actual randomisation and record result to redcap_dataN
     */
    protected function getRandomizeRecordResult($rid, $record, $fields, $group_id) {
        global $Proj,$project_id;
        $message = '-';
        $randAttrs = \Randomization::getRandomizationAttributes($rid);

        // Randomize and allocate table entry
        $randomizeResult = \Randomization::randomizeRecord($rid, $record, $fields, $group_id);
        if ($randomizeResult === false) {
            return array(false, \RCView::tt('global_01'), null); // ERROR
        } else if (is_string($randomizeResult)) {
            return array(false, $randomizeResult, null);
        } 

        list ($target_field, $target_field_value, $target_field_alt_value, $rand_time_server, $rand_time_utc) = \Randomization::getRandomizedValue($record, $rid);
        
        try {
            $saveResult = $this->savetoDataTable($record, $randAttrs['targetEvent'], $target_field, $target_field_value);
        } catch (\Throwable $th) {
            $saveResult = false;
        }

        if (!$saveResult) {
                // could not save allocation value to data table - roll back and return error
            \REDCap::updateRandomizationTableEntry($project_id, $rid, $randomizeResult, 'is_used_by', null, $this->getModuleName().' failed to update data table with allocation result: rolling back');
            return array(false, 'failed to record allocation result to record', null);
        }

        if ($randAttrs['isBlinded']) {
            $message = \RCView::b(\RCView::escape($target_field_value));
        } else {
            $choices = $this->getChoiceLabels($target_field);
            $choiceLabel = $this->formatLabel($choices[$target_field_value]);
            $message = \RCView::b($choiceLabel).' ('.\RCView::escape($target_field_value).') '.$target_field_alt_value;
        }
        
        // Log that randomization took place right after we saved the record values
    	\Logging::logEvent("", "redcap_data", "MANAGE", $record, "$Proj->table_pk = '$record'\nrandomization_id = $rid", "Randomize record", "", "", "", true, $randAttrs['targetEvent']);

        return array(true, $message, $target_field_value);
    }

    /**
     * writeAllocationValue()
     * Manually write allocation value to project's data table 
     * Can't use REDCap::saveData() because it is a rand target field
     */
    protected function savetoDataTable($record, $event_id, $field_name, $value, $instance=null): bool {
        global $Proj;
        $data_table = \REDCap::getDataTable($Proj->project_id);

        $sqlInsertAlloc = "INSERT INTO $data_table (project_id, event_id, record, field_name, `value`, instance) VALUES (?,?,?,?,?,?)";
        $params = array(intval($Proj->project_id),intval($event_id),"$record","$field_name","$value",$instance);
        $q = $this->query($sqlInsertAlloc, $params);

        if ($q) {
            $data_values = "$field_name = '$value'";
            $sqlInsertAlloc = "INSERT INTO $data_table (project_id, event_id, record, field_name, value, instance) VALUES ({$Proj->project_id}, $event_id, '$record', '$field_name', '$value', ".($instance ?? 'null').")";

            // also insert form status value if not alrready present
            $formStatusField = $Proj->metadata[$field_name]['form_name'].'_complete';
            $sql = "select 1 from $data_table where project_id=? and event_id=? and record=? and field_name=? ";
            $params = array(intval($Proj->project_id),intval($event_id),"$record","$formStatusField");
            if (is_null($instance)) {
                $sql .= "and instance is null";
            } else {
                $sql .= "and instance=?";
                $params[] = $instance;
            }

            $q = $this->query($sql, $params);
            
            if (!$q->num_rows) {
                $sqlInsertStatus = "INSERT INTO $data_table (project_id, event_id, record, field_name, value, instance) VALUES (?,?,?,?,?,?)";
                $params = array(intval($Proj->project_id),intval($event_id),"$record","$formStatusField","0",$instance);
                $q = $this->query($sqlInsertStatus, $params);

                $data_values .= ",\n$formStatusField = '0'";
                $sqlInsertStatus = "\nINSERT INTO $data_table (project_id, event_id, record, field_name, value, instance) VALUES ({$Proj->project_id}, $event_id, '$record', '$formStatusField', '$value', ".($instance ?? 'null').")";
            } else {
                $sqlInsertStatus = '';
            }

            \Logging::logEvent($sqlInsertAlloc.$sqlInsertStatus, "redcap_data", 'UPDATE', $record, $data_values, 'Update record', '', '', '', true, $event_id, $instance);
        }
        return (bool)$q;
    }

    public function formatLabel($label, $maxLen=50) {
        return truncateTextMiddle(strip_tags(\REDCap::filterHtml($label)), $maxLen);
    }

    /**
     * saveValueToField()
     * Save a single value to a single event/field for a record
     */
    public function saveValueToField($randomization_id, $record, $field, $value) {
        $randAttr = $this->getRandAttrs($randomization_id);
        $saveArray = array($this->makeSaveArrayElement($record, $randAttr['targetEvent'], $field, $value));
        $saveResult = \REDCap::saveData('json-array', $saveArray, 'overwrite'); // json_encode() not required for 'json-array' format
        if (isset($saveResult['errors']) && !empty($saveResult['errors']) ) {
            $this->logError($randomization_id, "Results save failed \n".print_r($saveResult, true)."\nData:\n".print_r($saveArray, true), $record);
        }
    }

    /**
     * makeSaveArrayElement()
     */
    protected function makeSaveArrayElement($record, $event_id, $field, $value, $instance=null) {
        global $Proj;
        $elem = array();
        $elem[$Proj->table_pk] = $record;
        if (\REDCap::isLongitudinal()) {
            $elem['redcap_event_name'] = \REDCap::getEventNames(true, false, $event_id);
        }
        if ($Proj->isRepeatingEvent($event_id)) {
            $elem['redcap_repeat_instrument'] = '';
            $elem['redcap_repeat_instance'] = $instance;

        } else if ($Proj->isRepeatingForm($event_id, $Proj->metadata[$field]['form_name'])) {
            $elem['redcap_repeat_instrument'] = $Proj->metadata[$field]['form_name'];
            $elem['redcap_repeat_instance'] = $instance;
        }
        $elem[$field] = $value;
        return $elem;
    }

    /**
     * logError()
     * Record details of error in module logging and notify project users specified in module settings
     */
    protected function logError(int $randomization_id, string $message, ?string $record=null, ?\Throwable $th=null): void {
        global $project_id;
        $randAttr = $this->getRandAttrs($randomization_id);
        \REDCap::logEvent($this->getModuleName(), $message.PHP_EOL.'See external module log for more information.', '', $record, $randAttr['targetEvent'], $project_id);
        $this->log($message. (is_null($th) ? '' : PHP_EOL.$th->getMessage().PHP_EOL.$th->getTraceAsString()));

        $failEmails = array_filter($this->getProjectSetting('fail-alert-email'), static function($var){return $var !== null;} );
        if (sizeof($failEmails)>0) {
            global $project_contact_email;
            $subject = "Extended Randomization External Module Error (pid=$project_id)";
            $body = [$subject];
            $body[] = "";
            $body[] = "Date/time: ".NOW;
            $body[] = "Project: $project_id";
            $body[] = "Randomization ID: $randomization_id";
            if (!is_null($record)) $body[] = "Record: $record";
            $body[] = "";
            $body[] = "Check the project's External Module Logging page for more details.";
            
            $email = new \Message();
            $email->setFrom($project_contact_email);
            $email->setTo(implode(',', $failEmails));
            $email->setSubject($subject);
            $email->setBody(implode('<br>',$body), true);
            $email->send();
        }
    }
    //endregion
}