<?php
/**
 * REDCap External Module: Extended Randomisation2
 * @author Luke Stevens, Murdoch Children's Research Institute
 */
namespace MCRI\ExtendedRandomisation2;

/**
 * RandomNumber
 * - When randomising to a text field, generate a random integer between the specified min and max
 * @author luke.stevens
 */
class RandomInteger extends AbstractRandomiser {
    public const USE_WITH_OPEN = true;
    public const USE_WITH_CONCEALED = true;
    protected const LABEL = 'Random Integer';
    protected const DESC = 'Generate a random integer between the specified min and max';

    protected $min;
    protected $max;
    
    /**
     * __construct
     */
    public function __construct(int $randomization_id, ExtendedRandomisation2 $module) {
        parent::__construct($randomization_id, $module);

        if ($this->config_current_randomiser_type=='RandomInteger' && is_array($this->config_current_settings_array) && !empty($this->config_current_settings_array)) {
            $configure = $this->validateConfigSettings($this->config_current_settings_array);
            if ($configure!==true) {
                throw new \Exception("Error in Random Integer config: $configure");
            }
        }
    }

    public function randomise() {
        $r = $this->getRandomNumber($this->min, $this->max, true);
        $tableCol = ($this->isBlinded) ? 'target_field' : 'target_field_alt';
        \REDCap::updateRandomizationTableEntry($this->project_id, $this->rid, $this->next_aid, $tableCol, $r, $this->module->getModuleName());
        $this->moduleLogEvent("Allocation id {$this->next_aid} allocated random number $r");
        return null;  // return and allow regular allocation to next aid
    }

    
    public function getConfigOptionDescription(): string { 
        $desc = static::DESC.'<ul>';
        $desc .= '<li><i class="fas fa-envelope-open text-success fs14 mr-1""></i>'.\RCView::tt('random_161').\RCView::tt('colon').' Regular group allocation, random integer recorded as randomization number (<code>[rand-number]</code>)</li>';
        $desc .= '<li><i class="fas fa-envelope text-danger fs14 mr-1""></i>'.\RCView::tt('random_162').\RCView::tt('colon').' Random integer overwrites uploaded target randomization number value for allocation.</li>';
        return $desc.'</ul>'; 
    }

    public function validateConfigSettings(array &$settings) {
        $errors = array();
        if (!array_key_exists('min', $settings) || $settings['min']==='') $errors[] = 'Missing min';
        $min = intval($settings['min']);
        if ($min!=$settings['min']) $errors[] = 'Non-integer min "'.$settings['min'].'"';
        $max = intval($settings['max']);
        if ($max!=$settings['max']) $errors[] = 'Non-integer max "'.$settings['max'].'"';
        if (!empty($errors)) return implode('; ',$errors);
        if ($min >= $max) return "Invalid range: min ($min) must be less than max ($max)";
        $this->min = $min;
        $this->max = $max;
        return true;
    }

    protected function getConfigOptionMarkupFields(): string { 
        $configFields = '<div class="container text-center">';
        $configFields .= '<div class="row">';
        $configFields .= '<div class="col-2 font-weight-bold text-right">Minimum</div>';
        $configFields .= '<div class="col-2"><input name="min" class="extrnd-RandomInteger"/></div>';
        $configFields .= '<div class="col-1 font-weight-bold">-</div>';
        $configFields .= '<div class="col-2"><input name="max" class="extrnd-RandomInteger"/></div>';
        $configFields .= '<div class="col-2 font-weight-bold text-left">Maximum</div>';
        $configFields .= '<div class="col-3"></div>';
        $configFields .= '</div>';
        $configFields .= '</div>';
        $configFields .= '<style>input.extrnd-RandomInteger { max-width: 100px; text-align: right; }</style>';
        return $configFields;
    }

    protected function updateRandomisationState(array $stratification, int $allocation) {  }
}