<?php
/**
 * REDCap External Module: Extended Randomisation2
 * @author Luke Stevens, Murdoch Children's Research Institute
 */
namespace MCRI\ExtendedRandomisation2;

/**
 * BiasedCoinMinimisation
 * Biased coin minimisation algorithm as per paper:
 * Randomization by minimization for unbalanced treatment allocation
 * B. HAN, N. H. ENAS AND D. MCENTAGART
 * Statist. Med. 2009; 28:3329-3346
 * DOI: https://doi.org/10.1002/sim.3710
 * @author luke.stevens
 */
class BiasedCoinMinimization extends AbstractRandomiser {
    public const USE_WITH_OPEN = true;
    public const USE_WITH_CONCEALED = true;
    protected const LABEL = 'Biased coin minimization';
    protected const DESC = "Dynamic randomization via the biased coin minimization algorithm described in this paper:<blockquote style='border-left:solid 3px #ddd;padding-left:1em;'>Han, B., Enas, N. H., & McEntegart, D. (2009). Randomization by minimization for unbalanced treatment allocation. <i>Statistics in medicine, 28</i>(27), 3329–3346. <a target='_blank' href='https://doi.org/10.1002/sim.3710'>https://doi.org/10.1002/sim.3710</a></blockquote><p>This biased coin minimization algorithm is suitable for use with either equal or unequal group allocation ratios.</p><p><strong>Allocation mechanism:</strong> The minimization algorithm calculates the group to assign dynamically, then updates the value on the allocation table entry being allocated to the record. Allocation tables may be generated with valid value as the group: whatever the table contains will be overwritten by the selected group upon allocation.<p><strong>Allocation tables/Dashboard view:</strong> For an improved Dashboard view, consider generating your allocation table with a dummy, placeholder value for the allocation group. Include this dummy group in your allocation field and specify an allocation ratio of <code>0</code>. Allocation table entries will be switched from the placeholder value to their real allocation group as they are allocated.</p></p><p><strong>Production status:</strong> Settings for strata weighting, allocation ratios, and base allocation probability are editable here only while the project is in Development status or no records have yet been randomized. The logging field setting may be altered as needed.</p>";
    protected const DEFAULT_ASSIGNMENT_PROB = 0.7;
    protected const DEFAULT_OVERALL_REF = 'OVERALL';
    public static $ProdEditableSettings = array('logging_field');
    
    protected $factor_weights;
    protected $allocation_ratios;
    protected $base_assignment_prob;
    protected $logging_field;
    protected $logging_steps;
    protected $use_stored_state = false; // possible future option to allow use of stored state rather than reading state from allocation table

    /**
     * __construct
     */
    public function __construct(int $randomization_id, ExtendedRandomisation2 $module, bool $applyConfig=true) {
        parent::__construct($randomization_id, $module);

        $this->factor_weights = array();
        $this->allocation_ratios = array();
        $this->base_assignment_prob = null;
        $this->logging_field = '';
        $this->logging_steps = array();

        if ($this->attrs['stratified'] > 0 || $this->attrs['group_by']=='DAG' || $this->attrs['group_by']=='FIELD') {
            if (count($this->attrs['strata'])) $this->factor_weights = array_fill_keys(array_keys($this->attrs['strata']), null); // includes group by FIELD case
            if ($this->attrs['group_by']=='DAG') $this->factor_weights['redcap_data_access_group'] = null;
            if (count($this->factor_weights)===1) $this->factor_weights[key($this->factor_weights)] = 1;
        }

        if ($this->isBlinded > 0) {
            $this->allocation_ratios = array(); // groups as well as set in config for blinded allocation
        } else {
            $targetFieldEnum = $this->module->getChoiceLabels($this->attrs['targetField']); 
            $this->allocation_ratios = array_fill_keys(array_keys($targetFieldEnum), null);
        }

        if ($applyConfig) $this->applySavedConfig();
    }

    public function applySavedConfig(): void {
        if ($this->config_current_randomiser_type=='BiasedCoinMinimization' && is_array($this->config_current_settings_array) && !empty($this->config_current_settings_array)) {
            $configure = $this->validateConfigSettings($this->config_current_settings_array);
            if ($configure!==true) {
                throw new \Exception("Error in Biased Coin Minimisation config: $configure");
            }
        } else {
            throw new \Exception("Error in Biased Coin Minimisation config: no saved settings to apply");
        }
    }

    public function validateConfigSettings(array &$settings) {
        global $Proj;
        $errors = array();

        foreach ($settings as $key => $value) {
            $value = trim($value);

            if ($key === 'base_assignment_prob') {
                if ($value=='') {
                    $errors[] = 'Missing base assignment probability';
                } else {
                    $baseprob = trim($settings['base_assignment_prob']);
                    if (is_numeric($baseprob) && floatval($baseprob)>=0 && floatval($baseprob<=1)) {
                        $this->base_assignment_prob = $settings[$key] = floatval($baseprob);
                    } else {
                        $errors[] = "Invalid base assignment probability: '$baseprob' (floating point number from 0 to 1 expected)";
                    }
                }
            }
            else if ($key === 'logging_field') {
                if ($value=='') {
                    $this->logging_field = $value;
                } else {
                    $targetEventForms = $this->module->getFormsForEventId($this->attrs['targetEvent']);
                    $logFieldForm = $Proj->metadata[$value]['form_name'] ?? '*';
                    if (in_array($logFieldForm, $targetEventForms)) {
                        $this->logging_field = $value;
                    } else {
                        $errors[] = "Invalid logging field: '$value'";
                    }
                }
            }
            else if (starts_with($key, 'factor_weights')) {
                list($label, $stratumField) = explode('-', $key, 2);
                
                if (array_key_exists($stratumField, $this->factor_weights)) {
                    if (is_numeric($value) && floatval($value)>0 && floatval($value)<=1) {
                        $this->factor_weights[$stratumField] = $settings[$key] = floatval($value);
                    } else {
                        $errors[] = "Invalid stratum weight for stratum '$stratumField': '$value' (non-zero floating point number less than or equal to 1 expected)";
                    }
                } else {
                    $errors[] = "Unrecognised stratum field '$stratumField'";
                }
            }
            else if (starts_with($key, 'allocation_ratios')) {
                if ($this->isBlinded) {
                    // value should be json object with group/ratio key value pairs [{"group":"A","ratio":1},{"group":"B","ratio":1}]
                    $valueFromJson = json_decode($value);
                    if (is_array($valueFromJson)) {
                        foreach ($valueFromJson as $grObj) {
                            $this->allocation_ratios[$grObj->group] = $grObj->ratio;
                        }
                    } else {
                        $errors[] = "Invalid specification of group allocation ratios";
                    }

                } else {
                    list($label, $allocGrp) = explode('-', $key, 2);
                    
                    if (array_key_exists($allocGrp, $this->allocation_ratios)) {
                        if (is_numeric($value) && intval($value)>=0 && ''.intval($value)=="$value" ) {
                            $this->allocation_ratios[$allocGrp] = $settings[$key] = intval($value);
                        } else {
                            $errors[] = "Invalid ratio value for allocation group '$allocGrp': '$value' (integer greater than or equal to zero expected)";
                        }
                    } else {
                        $errors[] = "Unrecognised allocation group '$allocGrp'";
                    }
                }
            }
            else {
                $errors[] = "Unexpected setting: '$key' = '$value'";
            }
        }

        // check all strata fields have a weight and they sum to 1
        if (count($this->factor_weights) > 1) {
            $wtSum = 0;
            foreach ($this->factor_weights as $sKey => $sw) {
                if (is_null($sw)) {
                    $errors[] = "Missing weight for stratum '$sKey'";
                } else {
                    $wtSum += $sw;
                }
            }
            if ($wtSum <= 0.999 || $wtSum > 1.0001) {
                $errors[] = "Stratum weights do not sum to 1 ($wtSum)";
            }
        }

        // verify all groups have an allocation ratio set, and at least one is >0
        $ratioSum = 0;
        foreach($this->allocation_ratios as $grp => $ratio) {
            if (is_null($ratio)) {
                $errors[] = "Missing allocation ratio for group '$grp'";
            } else {
                $ratioSum += $ratio;
            }
        }
        if ($ratioSum == 0) {
            $errors[] = "At least one group must have a non-zero allocation ratio";
        }

        if (!empty($errors)) return implode('; ',$errors);
        return true;
    }
    
    protected function getConfigOptionMarkupFields(): string { 
        global $Proj;

        $editable = $this->isConfigEditable();
        $bp = $this->base_assignment_prob ?? static::DEFAULT_ASSIGNMENT_PROB;

        $targetEventForms = $this->module->getFormsForEventId($this->attrs['targetEvent']);
        $lf = array('' => \RCView::tt('global_75')); // "None"
        foreach ($Proj->metadata as $field => $fieldAttrs) {
            if (in_array($fieldAttrs['form_name'], $targetEventForms) && $fieldAttrs['element_type']=='textarea') {
                $lf[$field] = $this->module->formatLabel($fieldAttrs['element_label']);
            }
        }
        $logField = \RCView::select(array('class'=>'mt-2', 'name'=>'logging_field'), $lf, $this->logging_field ?? ''); // always editable

        if ($this->isBlinded) {
            // blinded allocation, specify groups and ratios in textarea e.g. A, 1 B:1
            $blindedGroupRatios = array();
            foreach ($this->allocation_ratios as $group => $ratio) {
                $gr = new \stdClass();
                $gr->group = "$group";
                $gr->ratio = $ratio;
                $blindedGroupRatios[] = $gr;
            }
            $ratiosText = json_encode($blindedGroupRatios);
            $ratiosInfo = '</p><p>Specify groups and ratios as an array of objects in JSON format, e.g.<br><code>[{"group":"A","ratio":1},{"group":"B","ratio":1}]</code><br>Users will see the target randomizaton number only. The dynamically assigned group value will be recorded in the allocation table but is accessible in this project only by your Administrator.';
            $ratiosInput = ($editable) ? "<input class='mt-2' type='text' name='allocation_ratios' style='width:300px;' value='$ratiosText'></input>" : "<span class='mr-2 mt-2 text-muted' style='font-family:monospace;'><i class='fas fa-lock mr-1'></i>$ratiosText</span><input type='hidden' name='allocation_ratios' value='$ratiosText'></input>";
            if (!empty($blindedGroupRatios)) $ratiosInput .= '<pre style="margin-top:5px; width:300px; line-height:12px; font-size:10px;">'.json_encode($blindedGroupRatios, JSON_PRETTY_PRINT).'</pre>';
        } else {
            $targetFieldEnum = $this->module->getChoiceLabels($this->attrs['targetField']);
            $ratioOpts = array(0,1,2,3,4,5,6,7,8,9,10);
            $ratiosInfo = '';
            $ratiosInput = '';
            foreach ($this->allocation_ratios as $group => $ratio) {
                $groupDesc = "<span class='badge bg-primary mx-1'>$group</span>".$this->module->formatLabel($targetFieldEnum[$group]);
                $groupSel = ($editable) ? \RCView::select(array('name'=>"allocation_ratios-$group"), $ratioOpts, $ratio) : "<span class='mr-2 text-muted'><i class='fas fa-lock mr-1'></i>$ratio</span><input type='hidden' name='allocation_ratios-$group' value='$ratio'>";
                $ratiosInput .= "<p class='mt-1'>$groupSel $groupDesc</p>";
            }
        }

        $weightsInput = '';
        if (count($this->factor_weights) > 0) {
            $swHideClass = (count($this->factor_weights) > 1) ? '' : 'd-none';
            foreach ($this->factor_weights as $stratum => $wt) {
                if ($stratum=='redcap_data_access_group') {
                    $stratumDesc = \RCView::span(array('class'=>'badge bg-secondary mx-1'), 'redcap_data_access_group');
                    $stratumDesc .= \RCView::tt('global_78', false);
                } else {
                    $stratumDesc = \RCView::span(array('class'=>'badge bg-secondary mx-1'), $stratum);
                    $stratumDesc .= $this->module->formatLabel($Proj->metadata[$stratum]['element_label']);
                }
                $weightVal = ($editable) ? "<input name='factor_weights-$stratum' value='$wt' />" : "<span class='mr-2 text-muted'><i class='fas fa-lock mr-1'></i>$wt</span><input type='hidden' name='factor_weights-$stratum' value='$wt' />";
                $weightsInput .= "<p class='mt-1'>$weightVal $stratumDesc</p>";
            }
        } else {
            $swHideClass = 'd-none';
        }

        $bpInput = ($editable) ? "<input class='mt-2' name='base_assignment_prob' value='$bp'>" : "<div class='mt-2 text-muted'><i class='fas fa-lock mr-1'></i>$bp</div><input type='hidden' name='base_assignment_prob' value='$bp'>";

        $configFields =  '<div class="container-fluid">';

        $configFields .= '  <div class="row extrnd-bcm-setting-row">';
        $configFields .= '    <div class="col">';
        $configFields .= '      <p class="font-weight-bold">Group Allocation Ratios</p>';
        $configFields .= '      <p class="font-weight-normal">Specify the allocation ratio for each group.'.$ratiosInfo.'</p>';
        $configFields .= '    </div>';
        $configFields .= '    <div class="col pt-4">';
        $configFields .=        $ratiosInput;
        $configFields .= '    </div>';
        $configFields .= '  </div>';

        $configFields .= "  <div class='row extrnd-bcm-setting-row $swHideClass'>";
        $configFields .= '    <div class="col">';
        $configFields .= '      <p class="font-weight-bold">Strata Weighting</p>';
        $configFields .= '      <p class="font-weight-normal">Specify the weighting for each stratification factor. Weights should be floating point values greater than 0, and sum to 1.</p>';
        $configFields .= '    </div>';
        $configFields .= '    <div class="col pt-4">';
        $configFields .=        $weightsInput;
        $configFields .= '    </div>';
        $configFields .= '  </div>';


        $configFields .= '  <div class="row extrnd-bcm-setting-row">';
        $configFields .= '    <div class="col">';
        $configFields .= '      <p class="font-weight-bold">Base Assignment Probability</p>';
        $configFields .= '      <p class="font-weight-normal">Base probability that the record will actually be assigned to the preferred allocation group. Applies for the group(s) with lowest allocation ratio(s) and adjusted up for groups with higher allocation ratios. Typically around 0.7.</p>';
        $configFields .= '    </div>';
        $configFields .= '    <div class="col pt-4">';
        $configFields .=        $bpInput;
        $configFields .= '    </div>';
        $configFields .= '  </div>';
        
        $configFields .= '  <div class="row extrnd-bcm-setting-row">';
        $configFields .= '    <div class="col">';
        $configFields .= '      <p class="font-weight-bold">Comprehensive Logging</p>';
        $configFields .= '      <p class="font-weight-normal">Log details of minimisation calculations to this Notes field. (Must be in same event as target field.)</p>';
        $configFields .= '    </div>';
        $configFields .= '    <div class="col pt-4">';
        $configFields .=        $logField;
        $configFields .= '    </div>';
        $configFields .= '  </div>';
        
        $configFields .= '</div>';
        $configFields .= '<style>';
        $configFields .= '  .extrnd-bcm-setting-row { margin: 0.5em 0 0.5em 0; border-top: solid 1px #ddd; }';
        $configFields .= '  select[name^=allocation_ratios] { width: 50px; }';
        $configFields .= '  input[name^=factor_weights] { width: 50px; text-align: right; }';
        $configFields .= '  input[name=base_assignment_prob] { width: 50px; text-align: right; }';
        $configFields .= '</style>';
        return $configFields;
    }

    public function getConfigOptionDescription(): string { 
        $desc = static::DESC;
        if ($this->project_status=='0') {
            $desc .= "<div class=\"yellow\">Ensure that you test your settings thoroughly (e.g. using simulations via the Batch Randomization page) to ensure that you are obtaining appropriate results prior to employing the minimization algorithm for Production randomization.</div>";
        }
        return $desc;; 
    }

    protected function addLogStep(string $stepMessage): void {
        $this->logging_steps[] = $stepMessage;
    }

    protected function saveLoggingToField(): void {
        $this->module->saveValueToField($this->rid, $this->record, $this->logging_field, implode(PHP_EOL, $this->logging_steps));
    }

    public function randomise() {
        $this->addLogStep("Biased Coin Minimization Logging: Record ".$this->record);
                
        $stratification = $this->strata_field_values ?? array();
        if (is_null($this->group_id)) {
            $dagUniqueName = '';
        } else {
            $dagUniqueName = \REDCap::getGroupNames(true, $this->group_id);
            $stratification['redcap_data_access_group'] = $this->group_id;
        }
        
        if (count($stratification)) $this->addLogStep("Stratification: ".json_encode($stratification).' '.$dagUniqueName);

        // read current counts by factor/level/group
        $this->initialiseRandomisationState();

        // remove any groups with allocation ratio=0 from consideration
        $this->removeZeroRatioGroups();

        // get the group that minimises imbalance
        $this->addLogStep("");
        $this->addLogStep("Calculate preferred allocation group - group with minimal marginal imbalance");
        $preferred = $this->getPreferredAllocation($stratification);

        // allocate the preferred group with ratio-adjusted "high" prob, or switch to another with ratio-adjusted remaining "low" probability
        $this->addLogStep("");
        $this->addLogStep("Select group for final allocation: allocate the preferred group with ratio-adjusted \"high\" prob, or other group with ratio-adjusted remaining \"low\" probability");
        $selected = $this->getSelectedAllocation($preferred);

        // log algorithm calculations
        if (!empty($this->logging_field)) { $this->saveLoggingToField(); }
        
        // update next allocation with selected group
        $tableCol = ($this->isBlinded) ? 'target_field_alt' : 'target_field';
        \REDCap::updateRandomizationTableEntry($this->project_id, $this->rid, $this->next_aid, $tableCol, $selected, $this->module->getModuleName());

        // save updated counts to module config
        $this->updateRandomisationState($stratification, $selected);

        return null; // return and allow regular allocation to next aid (which has now had its group updated)
    }
    
    /**
     * readRandomisationState()
     * Read counts of previous randomisations by factor/level and group.
     * 
     * Randomisation state is a multidimensional associative array with keys corresponding to:
     * 1. Stratification factor (variable name)
     * 2. Factor level (value)
     * 3. Allocation group (value)
     * An integer count of records assigned to the factor/level/group is the ultimate value.
     * The overall count by group is also included.
     * 
     * array(
     *   "sex" =>
     *     "1" =>      // Male
     *       "1" => 6, /// Group 1: n=6
     *       "2" => 5  /// Group 2: n=5
     *     "2" => 
     *       "1" => 8,
     *       "2" => 7
     *   "redcap_data_access_group" => 
     *     "1111" =>   // group_id
     *       "1" => 3, // group_id 1111: n=3
     *       "2" => 5  // group_id 1112: n=5
     *     "1112" => 
     *       ...
     *   "OVERALL" =>
     *     "1" =>
     *       "1" => 14,
     *       "2" => 12 
     * )
     */
    protected function initialiseRandomisationState(): void {
        $this->randomisation_state = array();

        if ($this->use_stored_state) {
            $storedStateText = $this->module->getRandStateSettings($this->rid);
            try {
                if (empty($storedStateText)) {
                    $storedState = array();    
                } else {
                    $storedState = json_decode($storedStateText, true);
                }
            } catch (\Throwable $th) {
                $storedState = array();
            }
        }

        $factors = array_keys($this->factor_weights);
        $factors[] = static::DEFAULT_OVERALL_REF;

        foreach ($factors as $factor) {
            $levels = $this->getFactorLevels($factor);
            foreach ($levels as $level) {
                foreach (array_keys($this->allocation_ratios) as $group) {
                    if ($this->use_stored_state) {
                        $this->randomisation_state[$factor][$level][$group] = 
                            (isset($storedState[$factor][$level][$group])) 
                            ? $storedState[$factor][$level][$group]
                            : 0;
                    } else {
                        // read counts of randomisations for current factor/level/group
                        $this->randomisation_state[$factor][$level][$group] = $this->readAllocationCount("$factor","$level","$group");
                    }
                }
            }
        }
    }

    /**
     * getFactorLevels
     * Get an array of choice values for the specified factor field
     * @param string factor
     * @return array levels
     */
    protected function getFactorLevels($factor): array {
        if ($factor===static::DEFAULT_OVERALL_REF) {
            $levels = array('1');
        } else {
            $levels = array();
            foreach($this->attrs['stratFields'] as $sf) {
                if ($factor==$sf['field_name']) {
                    $levels = array_keys($sf['levels']);
                    break;
                }
            }
        }
        return $levels;
    }

    /**
     * readAllocationCount()
     * Read the allocation table and return the count of records randomised to the specified factor, level, and group.
     * @param string The stratification field. Optional (to count for group irrespective of stratum); ignored if level not specified.
     * @param string The level for the stratification field. Optional (to count for group irrespective of stratum).
     * @param string The allocation group. Optional (to count for stratum irrespective of group).
     * @return int The number of records randomised for this combination
     */
    protected function readAllocationCount(string $factor='', string $level='', string $group=''): int {
        $count = 0;
        $params = array($this->rid, $this->project_status);
        $andWhere = '';
        $stratColumn = '';
        if ($factor !== '' && $factor !== static::DEFAULT_OVERALL_REF) {
            // get allocation table column for this stratification factor
            if ($factor === 'redcap_data_access_group') {
                $stratColumn =  'group_id';
            } else {
                foreach ($this->attrs['stratFields'] as $stratField) {
                    if ($stratField['field_name'] === $factor) {
                        $stratColumn = "source_field".$stratField['source_field'];
                        break;
                    }
                }
            }
        }

        if ($stratColumn !== '' && $level !== '') {
            $andWhere .= " and $stratColumn = ?";
            $params[] = $level;
        }

        if ($group !== '') {
            $groupColumn = ($this->isBlinded) ? 'target_field_alt' : 'target_field';
            $andWhere .= " and $groupColumn = ?";
            $params[] = $group;
        }

        $sql = "select count(*) as n from redcap_randomization_allocation where rid = ? and project_status = ? and is_used_by is not null $andWhere";
        $result = $this->module->query($sql, $params);
        while ($row = $result->fetch_assoc()) {
            $count = $row['n'];
        }

        return $count;
    }

    /**
     * removeZeroRatioGroups()
     * Does what it says on the tin
     */
    protected function removeZeroRatioGroups() {
        foreach ($this->allocation_ratios as $group => $ratio) {
            if ($ratio == 0) {
                $this->addLogStep("-Group $group removed: allocation ratio=0");
                unset($this->allocation_ratios[$group]);
            }
        }
    }

    /**
     * getPreferredAllocation() 
     * Obtain the group that allocating the current record to will result in minimised allocation imbalance.
     * If more than one group provides equally mimimal imbalance then one is selected at random.
     * @param array $stratification
     * @return string $group
     */
    protected function getPreferredAllocation(array $stratification): string {
        /*
        * $stratification is an associative array of factor/level pairs for the current record.
        * array(
        *   "sex" => "1", 
        *   "redcap_data_access_group" => "1111"
        * )
        * or if no stratification
        * array( 'OVERALL' => '1' )
        */

        $marginalImbalanceByGroup = array();
        if (count($this->factor_weights)===0) $stratification = array(static::DEFAULT_OVERALL_REF=>'1');
            
        foreach (array_keys($this->allocation_ratios) as $proposed_group) {
            $this->addLogStep("-Calculate marginal imbalance score if allocate to Group={$proposed_group}");
            $totalMarginalImbalance = array();
            foreach ($stratification as $factor => $thisLevel) {
                $weighting = (count($this->factor_weights)===0)? 1 : $this->factor_weights[$factor];
                $adjustedCounts = $this->getAdjustedCounts($factor, $thisLevel, $proposed_group);

                $acClone = $adjustedCounts;
                $sumDiffs = 0;
                
                foreach ($adjustedCounts as $i => $ci) {
                    foreach ($acClone as $j => $cj) {
                        if ($i<$j) { $sumDiffs += abs($ci-$cj); }
                    }
                }
                
                $Nminus1 = count($adjustedCounts)-1;
                $sumAdjustedCounts = array_sum($adjustedCounts);
                $factorImbalance = $sumDiffs / ( $Nminus1 * $sumAdjustedCounts );
                $weightedImbalance = $factorImbalance * $weighting; 
                $this->addLogStep("-Imbalance score for Factor $factor=$thisLevel (weighting $weighting), Group={$proposed_group} = $sumDiffs / ($Nminus1 x $sumAdjustedCounts) = $factorImbalance, weighted = $factorImbalance x $weighting = $weightedImbalance");
                $totalMarginalImbalance[$factor] = $weightedImbalance;
            }
            $marginalImbalanceByGroup[$proposed_group] += array_sum($totalMarginalImbalance);
            $this->addLogStep("-Total marginal imbalance score for Group={$proposed_group} = ".implode('+', $totalMarginalImbalance)." = ".$marginalImbalanceByGroup[$proposed_group]);
            $this->addLogStep("");
        }
        
        $minimumImbalanceGroups = array_keys($marginalImbalanceByGroup, min($marginalImbalanceByGroup)); // return the group(s) with minimum imbalance
        $this->addLogStep("-Group(s) with minimum imbalance is(are): ".implode(' ', $minimumImbalanceGroups));
        
        return $this->selectRandomGroupInRatio($minimumImbalanceGroups); // will select 1 of equally preferred groups (at random in accordance with allocation ratio)
    }

    /**
     * getAdjustedCounts()
     * Get the adjusted count for the specified group assuming the current record is assigned to this group.
     * Adjustment is made by dividing by the group's allocation ratio.
     * @param string $factor
     * @param string $level
     * @param string $group
     * @return array $adjusted_counts
     */
    protected function getAdjustedCounts(string $factor, string $level, string $proposed_group): array {
        $adjustedCounts = array();
        $this->addLogStep("--Factor $factor=$level group counts: current and adjusted for proposed allocation and allocation ratio:");
        foreach ($this->allocation_ratios as $group => $ratio) {
            $thisAdjust = ($proposed_group==$group) ? 1 : 0;
            // adjust counts by assuming allocation to this group and dividing by target allocation ratio
            $count = $this->readCurrentAllocationCount($factor, $level, $group);
            $adjustedCount = ($count+$thisAdjust)/$ratio;
            $adjustedCounts[$group] = $adjustedCount;
            $this->addLogStep("---Group=$group: current=$count, adjusted=($count+$thisAdjust) / {$ratio} = $adjustedCount; ");
        }
        return $adjustedCounts;
    }
    
    /**
     * readCurrentAllocationCount()
     * Get the current count of allocations for the specified factor and/or level and/or group.
     * Factor, level, and group are all optional.
     * Zero will be returned if an unrecognised factor/level/group specified
     * @param ?string factor
     * @param ?string level
     * @param ?string group
     * @return int counts
     */
    protected function readCurrentAllocationCount(string $factor=null, string $level=null, string $group=null): int {
        $currentCount = 0;
        if (is_null($this->randomisation_state)) $this->initialiseRandomisationState();
        $includeOverall = ($factor==static::DEFAULT_OVERALL_REF || !$this->attrs['isStratified']); // only include the "OVERALL" state element if it is explicitly requested or if it is the only element (because no stratification)

        foreach ($this->randomisation_state as $loopFactor => $loopLevels) {
            if ($loopFactor==static::DEFAULT_OVERALL_REF && !$includeOverall) continue;
            if (!is_null($factor) && $factor != $loopFactor) continue;

            foreach ($loopLevels as $loopLevel => $loopGroups) {
                if (!is_null($level) && $level != $loopLevel) continue;

                foreach ($loopGroups as $loopGroup => $loopGroupCount) {
                    if (!is_null($group) && $group != $loopGroup) continue;
                    $currentCount += $loopGroupCount;
                }
            }
        }
        return $currentCount;
    }
    
    /**
     * selectRandomGroupInRatio()
     * Makes an array of specified groups with count of each group corresponding to the group's allocation ratio
     * e.g. A:B:C ratio 2:1:1 -> AABC
     * Returns one group selected at random.
     * @param array groupsToSelectFrom
     * @return string selectedGroup
     */
    protected function selectRandomGroupInRatio(array $selectFrom): string {
        $choicesInRatio = array();
        if (count($selectFrom)===1) { 
            $a = (string)$selectFrom[0]; // only one other group!
            $choicesInRatio[] = $a;
            $indexToPick = 0;
        } else {
            // when multiple alternatives, select one at random but account for desired allocation ratio
            foreach ($this->allocation_ratios as $group => $ratio) {
                if (in_array($group, $selectFrom)) {
                    $i=0;
                    while($i < $ratio) {
                        $choicesInRatio[] = $group;
                        $i++;
                    }
                }
            }
            $indexToPick = $this->getRandomNumber(0, count($choicesInRatio)-1, true);
            $a = (string)$choicesInRatio[$indexToPick];
            $this->addLogStep("--Random selection: index $indexToPick from [".implode(',', $choicesInRatio)."] = group $a ");
        }
        return $a;
    }
    
    /**
     * getSelectedAllocation()
     * Returns the preferred group with the base assignment probability
     * Returns an alternative allocation group the rest of the time, at random in proportion to those groups' allocation ratios
     * @param string preferredGroup
     * @return string selectedGroup
     */
    protected function getSelectedAllocation(string $preferred): string {
        $groupAllocationProbabilities = array();
        foreach (array_keys($this->allocation_ratios) as $group) {
            if ($group == $preferred) {
                $groupAllocationProbabilities[$group] = $this->getHighProb($group);
            } else {
                $groupAllocationProbabilities[$group] = $this->getLowProb($group);
            }
        }
        
        $logMsg = '-Group allocation probabilities:';
        foreach ($groupAllocationProbabilities as $g => $p) {
            $logMsg .= " $g=$p";
        }
        $this->addLogStep($logMsg);
        
        $randomNumber = $this->getRandomNumber(0, 1, false);
        $cumulativeP = 0;
        $allocation = '';
        foreach ($groupAllocationProbabilities as $g => $p) {
            $cumulativeP += $p;
            if ($cumulativeP > $randomNumber) {
                // allocate the current g
                $allocation = $g;
                break;
            }
        }
        
        $this->addLogStep("-Random number=$randomNumber => group $allocation selected.");
        return (string)$allocation;
    }
    
    /**
     * getGroupWithLowestRatio()
     * Get the allocation that has the lowest allocation ratio. If multiple groups share this ratio the first is returned.
     * @return string group
     */
    protected function getGroupWithLowestRatio(): string {
        $lowestRatio = null;
        $lowestRatioGroup = null;
        foreach ($this->allocation_ratios as $group => $ratio) {
            if (is_null($lowestRatio) || $ratio < $lowestRatio) {
                $lowestRatio = $ratio;
                $lowestRatioGroup = $group;
            }
        }
        return (string)$lowestRatioGroup;
    }

    /**
     * sumRatiosExceptGroup()
     * Get the sum of groups' ratios, (optionally, except one specified)
     * @param ?string exceptGroup
     * @return float sum
     */
    protected function sumRatiosExceptGroup($except=null) {
        $sum = 0;
        foreach ($this->allocation_ratios as $group => $ratio) {
            if ($group !== $except) {
                $sum += $ratio;
            }
        }
        return $sum;
    }
    
    /**
     * getHighProb()
     * Calculate the probability of assignment for the specified group (which will be the preferred allocation).
     * Group with the lowest allocation ratio gets the baseline allocation probability (e.g. 70%) but this is adjusted up for higher probablility groups.
     * @param string group
     * @return float probability
     */
    protected function getHighProb($grp): float {
        $lowestRatioGroup = $this->getGroupWithLowestRatio();
        $sumRatiosNotLowest = $this->sumRatiosExceptGroup($lowestRatioGroup);
        $sumRatiosNotPreferred = $this->sumRatiosExceptGroup($grp);
        
        return (float)(1 - ($sumRatiosNotPreferred / $sumRatiosNotLowest) * (1 - $this->base_assignment_prob));
    }
    
    /**
     * getLowProb()
     * Calculate the probability of assignment for the specified group (which will one of the non-preferred allocations).
     * Will be some part of 1-0.7=0.3 when preferred group is that with the lowest allocation ratio, adjusted according to the allocation ratio of the specified group in relation to the other groups' ratios.
     * @param string group
     * @return float probability
     */
    protected function getLowProb($grp): float {
        $lowestRatioGroup = $this->getGroupWithLowestRatio();
        $sumRatiosNotLowest = $this->sumRatiosExceptGroup($lowestRatioGroup);
        $thisGroupRatio = $this->allocation_ratios[$grp];
        return (float)(($thisGroupRatio / $sumRatiosNotLowest) * (1 - $this->base_assignment_prob));
    }
    
    /**
     * updateRandomisationState()
     * Increment the count for the selected group for the current stratification factors (and overall)
     * Save to module settings
     * @param array stratification
     * @param string selectedAllocationGroup
     */
    protected function updateRandomisationState($stratification, $group): void {

        $this->randomisation_state[static::DEFAULT_OVERALL_REF]['1'][$group]++;

        foreach ($stratification as $factor => $level) {
            $this->randomisation_state[$factor][$level][$group]++;
        }

        $project_settings = $this->module->getProjectSettings($this->project_id);

        $project_settings['rand-state'][$this->config_current_index] = \json_encode($this->randomisation_state, JSON_FORCE_OBJECT);

        $this->module->setProjectSettings($project_settings, $this->project_id);
    }
}
