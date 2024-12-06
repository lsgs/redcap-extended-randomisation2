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
    protected const DESC = "Dynamic randomization via the biased coin minimization algorithm described in <blockquote>Han, B., Enas, N. H., & McEntegart, D. (2009). Randomization by minimization for unbalanced treatment allocation. <i>Statistics in medicine, 28</i>(27), 3329–3346. https://doi.org/10.1002/sim.3710</blockquote>";
    protected const DEFAULT_ASSIGNMENT_PROB = 0.7;

    protected $stratum_weights;
    protected $allocation_ratios;
    protected $base_assignment_prob;
    protected $logging_field;

    /**
     * __construct
     */
    public function __construct(int $randomization_id, ExtendedRandomisation2 $module) {
        parent::__construct($randomization_id, $module);

        $this->stratum_weights = array();
        $this->allocation_ratios = array();
        $this->base_assignment_prob = null;
        $this->logging_field = '';

        if ($this->attrs['stratified'] > 0) {
            if (count($this->attrs['strata'])) $this->stratum_weights = array_fill_keys(array_keys($this->attrs['strata']), null);
            if ($this->attrs['group_by']=='DAG') $this->stratum_weights['dag'] = null;
            if (count($this->stratum_weights)===1) $this->stratum_weights[key($this->stratum_weights)] = 1;
        }

        if ($this->isBlinded > 0) {
            $this->allocation_ratios = array(); // groups as well as set in config for blinded allocation
        } else {
            global $Proj;
            $targetFieldEnum = $this->module->getChoiceLabels($this->attrs['targetField']); 
            $this->allocation_ratios = array_fill_keys(array_keys($targetFieldEnum), null);
        }

        if ($this->config_current_randomiser_type=='BiasedCoinMinimization' && is_array($this->config_current_settings_array) && !empty($this->config_current_settings_array)) {
            $configure = $this->validateConfigSettings($this->config_current_settings_array);
            if ($configure!==true) {
                throw new \Exception("Error in Biased Coin Minimisation config: $configure");
            }
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
            else if (starts_with($key, 'stratum_weights')) {
                list($label, $stratumField) = explode('-', $key, 2);
                
                if (array_key_exists($stratumField, $this->stratum_weights)) {
                    if (is_numeric($value) && floatval($value)>0 && floatval($value)<=1) {
                        $this->stratum_weights[$stratumField] = $settings[$key] = floatval($value);
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
                    // TODO - set $this->allocation_ratios
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
        if (count($this->stratum_weights) > 1) {
            $wtSum = 0;
            foreach ($this->stratum_weights as $sKey => $sw) {
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
            $errors[] = "At least one group must have an allocation ratios greater than zero";
        }

        if (!empty($errors)) return implode('; ',$errors);
        return true;
    }
    
    protected function getConfigOptionMarkupFields(): string { 
        global $Proj;

        $bp = $this->base_assignment_prob ?? static::DEFAULT_ASSIGNMENT_PROB;

        $targetEventForms = $this->module->getFormsForEventId($this->attrs['targetEvent']);
        $lf = array('' => \RCView::tt('global_75')); // "None"
        foreach ($Proj->metadata as $field => $fieldAttrs) {
            if (in_array($fieldAttrs['form_name'], $targetEventForms) && $fieldAttrs['element_type']=='textarea') {
                $lf[$field] = $this->module->formatLabel($fieldAttrs['element_label']);
            }
        }
        $logField = \RCView::select(array('class'=>'mt-4', 'name'=>'logging_field'), $lf, $this->logging_field ?? '');

        if ($this->isBlinded) {
            // blinded allocation, specify groups and ratios in textarea e.g. A, 1 B:1
            $ratios = 'TODO'; // TODO json_encode($currentSettings['allocation_ratios']);
            $ratiosInfo = '</p><p>Specify groups and ratios as an array of objects in JSON format, e.g. <code>[{"group":"A","ratio":1},{"group":"B","ratio":1}]</code>. The assigned group value will be recorded in the allocation table but is accessible in this project only be your Administrator. Users will see the target randomizaton number only.';
            $ratiosInput = "<input type='text' name='allocation_ratios' value='$ratios'></input>";
        } else {
            $targetFieldEnum = $this->module->getChoiceLabels($this->attrs['targetField']);
            $ratioOpts = array(0,1,2,3,4,5,6,7,8,9,10);
            $ratiosInfo = '';
            $ratiosInput = '';
            foreach ($this->allocation_ratios as $group => $ratio) {
                $groupDesc = "<span class='badge bg-primary'>$group</span>".' '.$this->module->formatLabel($targetFieldEnum[$group]);
                $groupSel = \RCView::select(array('name'=>"allocation_ratios-$group"), $ratioOpts, $ratio);
                $ratiosInput .= "<p class='mt-1'>$groupSel $groupDesc</p>";
            }
        }

        if (count($this->stratum_weights) > 0) {
            $swHideClass = (count($this->stratum_weights) > 1) ? '' : 'd-none';
            foreach ($this->stratum_weights as $stratum => $wt) {
                $stratumDesc = \RCView::span(array('class'=>'badge bg-secondary'), $stratum);
                if ($stratum=='dag') {
                    $stratumDesc .= \RCView::tt('global_78', false);
                } else {
                    $stratumDesc .= $this->module->formatLabel($Proj->metadata[$stratum]['element_label']);
                }
                $ratiosInput .= "<p class='mt-1'><input name='stratum_weights-$stratum' value='$wt' /> $stratumDesc</p>";
            }
        } else {
            $swHideClass = 'd-none';
            $weightsInput = '';
        }

        $configFields =  '<div class="container-fluid">';

        $configFields .= '  <div class="row extrnd-bcm-setting-row">';
        $configFields .= '    <div class="col">';
        $configFields .= '      <p class="font-weight-bold">Group Allocation Ratios</p>';
        $configFields .= '      <p class="font-weight-normal">Specify the allocation ratio for each group. Ratios should be integer values.'.$ratiosInfo.'</p>';
        $configFields .= '    </div>';
        $configFields .= '    <div class="col">';
        $configFields .=        $ratiosInput;
        $configFields .= '    </div>';
        $configFields .= '  </div>';

        $configFields .= "  <div class='row extrnd-bcm-setting-row $swHideClass'>";
        $configFields .= '    <div class="col">';
        $configFields .= '      <p class="font-weight-bold">Strata Weighting</p>';
        $configFields .= '      <p class="font-weight-normal">Specify the weighting for each stratification factor. Weights should be floating point values between 0 and 1, and sum to 1.</p>';
        $configFields .= '    </div>';
        $configFields .= '    <div class="col">';
        $configFields .=        $weightsInput;
        $configFields .= '    </div>';
        $configFields .= '  </div>';


        $configFields .= '  <div class="row extrnd-bcm-setting-row">';
        $configFields .= '    <div class="col">';
        $configFields .= '      <p class="font-weight-bold">Base Assignment Probability</p>';
        $configFields .= '      <p class="font-weight-normal">The probability that the preferred allocation group will actually be assigned to the record. Typically around 0.7.</p>';
        $configFields .= '    </div>';
        $configFields .= '    <div class="col">';
        $configFields .= '      <input class="mt-4" name="base_assignment_prob" value="'.$bp.'">';
        $configFields .= '    </div>';
        $configFields .= '  </div>';
        
        $configFields .= '  <div class="row extrnd-bcm-setting-row">';
        $configFields .= '    <div class="col">';
        $configFields .= '      <p class="font-weight-bold">Comprehensive Logging</p>';
        $configFields .= '      <p class="font-weight-normal">Log details of minimisation calculations to this Notes field. (Must be in same event as target field.)</p>';
        $configFields .= '    </div>';
        $configFields .= '    <div class="col">';
        $configFields .=        $logField;
        $configFields .= '    </div>';
        $configFields .= '  </div>';
        
        $configFields .= '</div>';
        $configFields .= '<style>';
        $configFields .= '  .extrnd-bcm-setting-row { margin: 0.5em 0 0.5em 0; border-top: solid 1px #ddd; }';
        $configFields .= '  select[name^=allocation_ratios] { width: 50px; }';
        $configFields .= '  input[name^=stratum_weights] { width: 50px; text-align: right; }';
        $configFields .= '  input[name=base_assignment_prob] { width: 50px; text-align: right; }';
        $configFields .= '</style>';
        return $configFields;
    }
    
    /**
     * Check have selections for required fields and allocate at random to
     * one of the selected options.
     * @param mixed $params
     * @return string
     */
    public function randomise() {
        return null;
        throw new \Exception('BiasedCoinMinimization randomization not implemented');
        /*
                $this->randomisation_context = $context;
                $this->logger = $logger;
                
                $this->logger->log("***** Biased Coin Minimisation: Record ".$context->getRecord()." *****");
                
                $gotRequiredData = $this->checkRequiredFields($params);
                if ($gotRequiredData !== true) {
                        $msg = 'Cannot randomise! Missing required data! '. implode(' | ', $gotRequiredData);
                        $logger->log($msg);
                        throw new ExtendedRandomisationFailedException($msg);
                }
                
                $stratification = array();
                foreach ($params as $fname => $attr) {
                        if ($fname==='redcap_data_access_group' && is_numeric($attr['value'])) {
                                $stratification[$fname] = \REDCap::getGroupNames(true, $attr['value']);
                        } else {
                                $stratification[$fname] = $attr['value'];
                        }
                }
                
                $this->logger->log("Stratification values are ".$this->keyValuePairsString($stratification));
                
                $preferred = $this->getPreferredAllocation($stratification);

                $allocation = $this->doAllocation($preferred);
                
                $this->updateRandomisationState($stratification, $allocation);
                $this->logger->log("Update {$this->randomisation_context->getEventId()} {$this->randomisation_context->getRandomisationField()} randomisation state: ".json_encode($this->randomisation_context->getCurrentRandomisationState()));
                
                return $allocation;*/
        }
        
/*        protected function getPreferredAllocation($stratification) {
                
                /*
                 * $stratification is an associative array of factor/level pairs
                 * for the current record.
                 * array(
                 *   "redcap_data_access_group" => "dag_1",
                 *   "sex" => "1" 
                 * )
                 * /

                $marginalImbalanceByGroup = array();
                
                foreach ($this->config->allocations_ratios as $proposed_group) {
                        $totalMarginalImbalance = array();
                        foreach ($stratification as $factor => $thisLevel) {
                                $weighting = $this->getFactorWeight($factor);
                                $adjustedCounts = $this->getAdjustedCounts($factor, $thisLevel, $proposed_group->group);

                                $acClone = $adjustedCounts; // clone, not reference
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
                                $this->logger->log("-Imbalance score for Factor $factor=$thisLevel (weighting $weighting), Group={$proposed_group->group} = $sumDiffs ⁄ ($Nminus1 x $sumAdjustedCounts) = $factorImbalance, weighted = $factorImbalance x $weighting = $weightedImbalance");
                                $totalMarginalImbalance[$factor] = $weightedImbalance;
                        }
                        $marginalImbalanceByGroup[$proposed_group->group] += array_sum($totalMarginalImbalance);
                        $this->logger->log("Total marginal imbalance score for Group={$proposed_group->group} = ".implode('+', $totalMarginalImbalance)." = ".$marginalImbalanceByGroup[$proposed_group->group]);
                }
                
                $minimumImbalanceGroups = array_keys($marginalImbalanceByGroup, min($marginalImbalanceByGroup)); // return the group(s) with minimum imbalance
                $this->logger->log("Group(s) with minimum imbalance is(are): ".implode(' ', $minimumImbalanceGroups));
                
                return $this->selectRandomGroupInRatio($minimumImbalanceGroups); // will select 1 of equally preferred groups
        }

        protected function getAdjustedCounts($factor, $thisLevel, $proposed_group) {
                
                $adjustedCounts = array();
                $logMsg = '';
                foreach ($this->config->allocations_ratios as $grp) {
                        $thisAdjust = ($proposed_group==$grp->group) ? 1 : 0;
                        // adjust counts by assuming allocation to this group and dividing by target allocation ratio
                        $count = $this->readCurrentAllocationCountsForFactorLevelGroup($factor, $thisLevel, $grp->group);
                        $adjustedCount = ($count+$thisAdjust)/$grp->ratio;
                        $adjustedCounts[$grp->group] = $adjustedCount;
                        $logMsg .= "{$grp->group} current=$count, adjusted=($count+$thisAdjust) ⁄{$grp->ratio}=$adjustedCount; ";
                }
                $this->logger->log("-Factor $factor=$thisLevel Group counts: $logMsg");
                return $adjustedCounts;
        }
        
        protected function getFactorWeight($factor) {
                foreach ($this->config->factors_weights as $f) {
                        if ($f->factor_name === $factor) {
                            return $f->weighting;
                        }
                }
                throw new ExtendedRandomisationFailedException("Could not get weighting for factor ".$f->factor_name);
        }
        
        /**
         * readCurrentAllocationCountsForFactorLevel($factor, $level)
         * 
         * Randomisation state is an array of objects, each object 
         * corresponding to a stratification factor (e.g. site).
         * The factor contains an array of objects, one per level (e.g. 
         * site number), with each level containing an array of objects,
         * one per allocation group, containing the current number of 
         * allocations for that factor/level/group.
         * 
         * [
         *   {
         *     "factor_name": "redcap_data_access_group",
         *     "levels": [
         *       {
         *         "level_value": "dag_1",
         *         "allocations": [
         *           {
         *             "group_value": "1",
         *             "group_n": 3
         *           },
         *           {
         *             "group_value": "2",
         *             "group_n": 2
         *           }
         *         ]
         *       },
         *       {
         *         "level_value": "dag_2",
         *         "allocations": [
         *           {
         *             "group_value": "1",
         *             "group_n": 14
         *           },
         *           {
         *             "group_value": "2",
         *             "group_n": 15
         *           }
         *         ]
         *       }
         *     ]
         *   },
         *   {
         *     "factor_name": "sex",
         *     "levels": [
         *       ...
         *     ]
         *   }
         * ]
         * 
         * @param string $factor
         * @param string $level
         * return array $countsForFactorLevel e.g. array("1"=>12, "2"=>11)
         * /
        protected function readCurrentAllocationCountsForFactorLevel($factor, $level) {
                $randomisationState = $this->randomisation_context->getCurrentRandomisationState();
                $countsForFactorLevel = array();
                $groups = array_keys($this->config->allocations_ratios);
                $foundFactor = false;
                
                foreach ($randomisationState as $stateFactor) {
                        if ($stateFactor->factor_name === $factor) {
                                $foundFactor = true;
                                foreach ($stateFactor->levels as $stateLevel) {
                                        if ($stateLevel->level_value === $level) {
                                                foreach ($stateLevel->allocations as $grp) {
                                                        $countsForFactorLevel[$grp->group_value] = $grp->group_n;
                                                }
                                                break;
                                        }
                                }
                                break;
                        }
                }

                if (!$foundFactor) {
                        foreach ($this->config->allocations_ratios as $group) {
                                $countsForFactorLevel[$group->group] = 0;
                        }
                }
                
                return $countsForFactorLevel;
        }

        protected function readCurrentAllocationCountsForFactorLevelGroup($factor, $level, $group) {
                $randomisationState = $this->randomisation_context->getCurrentRandomisationState();
                $foundFactor = false;
                
                foreach ($randomisationState as $stateFactor) {
                        if ($stateFactor->factor_name === $factor) {
                                $foundFactor = true;
                                foreach ($stateFactor->levels as $stateLevel) {
                                        if ($stateLevel->level_value === $level) {
                                                foreach ($stateLevel->allocations as $grp) {
                                                        if ($grp->group_value == $group) { return $grp->group_n; }
                                                }
                                                break;
                                        }
                                }
                                break;
                        }
                }
                return 0;
        }
        
        protected function selectRandomGroupInRatio(array $selectFrom) {
                $choicesInRatio = array();
                if (count($selectFrom)===1) { 
                        $a = $selectFrom[0]; // only one other group!
                        $choicesInRatio[] = $a;
                } else {
                        // when multiple alternatives, select one at random but account for desired allocation ratio
                        foreach ($this->config->allocations_ratios as $alloc) {
                                if (in_array($alloc->group, $selectFrom)) {
                                        $i=0;
                                        while($i < $alloc->ratio) {
                                                $choicesInRatio[] = $alloc->group;
                                                $i++;
                                        }
                                }
                        }
                        $a = (string)$choicesInRatio[array_rand($choicesInRatio)];
                }
                $this->logger->log("Allocation $a selected from ".implode(',', $choicesInRatio));
                return $a;
        }
        
        protected function doAllocation($preferred) {
                $groupAllocationProbabilities = array();
                foreach ($this->config->allocations_ratios as $grp) {
                        if ($grp->group == $preferred) {
                                $groupAllocationProbabilities[$grp->group] = $this->getHighProb($grp->group);
                        } else {
                                $groupAllocationProbabilities[$grp->group] = $this->getLowProb($grp->group);
                        }
                }
                
                $logMsg = 'Group allocation probabilities:';
                foreach ($groupAllocationProbabilities as $g => $p) {
                        $logMsg .= " $g=$p";
                }
                $this->logger->log($logMsg);
                
                $randomNumber = $this->getRandomNumber();
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
                
                $this->logger->log("Random number=$randomNumber, group $allocation selected.");
                return $allocation;
        }
        
        protected function getAllocationWithLowestRatio() {
                $lowestAlloc = '';
                $lowestRatio = '';
                foreach ($this->config->allocations_ratios as $grp) {
                        if ($lowestRatio==='' || $grp->ratio < $lowestRatio) {
                                $lowestAlloc = $grp->group;
                                $lowestRatio = $grp->ratio;
                        }
                }
                return $lowestAlloc;
        }
        
        protected function getGroupRatio($g) {
                foreach ($this->config->allocations_ratios as $grp) {
                        if ($grp->group === $g) { return $grp->ratio; }
                }
                throw new ExtendedRandomisationFailedException("Could not get ratio for group $g");
        }
        
        protected function sumRatiosExceptGroup($except) {
                $sum = 0;
                foreach ($this->config->allocations_ratios as $grp) {
                        if ($grp->group !== $except) {
                                $sum += $grp->ratio;
                        }
                }
                return $sum;
        }
        
        protected function getHighProb($grp) {
                // the allocation with the lowest ratio gets the baseline allocation probability e.g. 70%, other groups will get a higher probablility
                $lowestRatioGroup = $this->getAllocationWithLowestRatio();
                $sumRatiosNotLowest = $this->sumRatiosExceptGroup($lowestRatioGroup);
                $sumRatiosNotPreferred = $this->sumRatiosExceptGroup($grp);
                
                return 1 - ($sumRatiosNotPreferred / $sumRatiosNotLowest) * (1 - ($this->config->base_assignment_probability/100));
        }
        
        protected function getLowProb($grp) {
                // the allocation with the lowest ratio gets the baseline allocation probability e.g. 70%, other groups will get a higher probablility
                $lowestRatioGroup = $this->getAllocationWithLowestRatio();
                $sumRatiosNotLowest = $this->sumRatiosExceptGroup($lowestRatioGroup);
                
                return ($this->getGroupRatio($grp) / $sumRatiosNotLowest) * (1 - ($this->config->base_assignment_probability/100));
        }
        
        protected function updateRandomisationState($stratification, $allocation) {
                $currentState = $this->randomisation_context->getCurrentRandomisationState();
                foreach ($stratification as $thisFactor => $thisLevel) {
                
                        foreach ($currentState as $stateFactor) {
                                if ($stateFactor->factor_name === $thisFactor) {
                                        $foundFactor = true;
                                        $foundLevel = false;
                                        foreach ($stateFactor->levels as $stateLevel) {
                                                if ($stateLevel->level_value === $thisLevel) {
                                                        $foundLevel = true;
                                                        $foundGroup = false;
                                                        foreach ($stateLevel->allocations as $group) {
                                                                if ($group->group_value == $allocation) {
                                                                        $foundGroup = true;
                                                                        $group->group_n++;
                                                                }
                                                        }
                                                        if (!$foundGroup) {
                                                                $stateLevel->allocations = $this->getNewStateAllocations($allocation);
                                                        }
                                                        break;
                                                }
                                        }
                                        if (!$foundLevel) {
                                                $stateFactor->levels[] = $this->getNewStateLevel($thisLevel, $allocation);
                                        }
                                        break;
                                }
                        }

                        if (!$foundFactor) {
                                $currentState[] = $this->getNewStateFactor($thisFactor, $thisLevel, $allocation);
                        }
                }
                $this->randomisation_context->setCurrentRandomisationState($currentState);
        }
        
        /**
         * [
         *   {
         *     "factor_name": "redcap_data_access_group",
         *     "levels": [
         *       {
         *         "level_value": "dag_1",
         *         "allocations": [
         *           {
         *             "group_value": "1",
         *             "group_n": 3
         *           },
         *           {
         *             "group_value": "2",
         *             "group_n": 2
         *           }
         *         ]
         *       },
         *       {
         *         "level_value": "dag_2",
         *         "allocations": [
         *           {
         *             "group_value": "1",
         *             "group_n": 14
         *           },
         *           {
         *             "group_value": "2",
         *             "group_n": 15
         *           }
         *         ]
         *       }
         *     ]
         *   },
         *   {
         *     "factor_name": "sex",
         *     "levels": [
         *       ...
         *     ]
         *   }
         * ]
         * /
        protected function getNewStateAllocations($allocation) {
                $allocations = array();
                foreach ($this->config->allocations_ratios as $group) {
                        $add = ($group->group==$allocation) ? 1 : 0;
                        $grp = new \stdClass();
                        $grp->group_value = $group->group;
                        $grp->group_n = $add;
                        $allocations[] = $grp;
                }
                return $allocations;
        }
        
        protected function getNewStateLevel($level, $allocation) {
                $l = new \stdClass();
                $l->level_value = $level;
                $l->allocations = $this->getNewStateAllocations($allocation);
                return $l;
        }
        
        protected function getNewStateFactor($factor, $level, $allocation) {
                $f = new \stdClass();
                $f->factor_name = $factor;
                $f->levels = array($this->getNewStateLevel($level, $allocation));
                return $f;
        }*/

    protected function updateRandomisationState(array $stratification, int $allocation) {  }
}
