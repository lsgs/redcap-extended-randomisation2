<?php
/**
 * REDCap External Module: Extended Randomisation2
 * @author Luke Stevens, Murdoch Children's Research Institute
 */
namespace MCRI\ExtendedRandomisation2;

/**
 * AbstractRandomiser
 *
 * @author luke.stevens
 */
abstract class AbstractRandomiser {
    public const USE_WITH_OPEN = true;
    public const USE_WITH_CONCEALED = true;
    protected const LABEL = 'Default';
    protected const DESC = 'The <em>Extended Randomization</em> external module will not be active for this randomization. Randomization will be performed using REDCap\'s standard randomization mechanism.';
    protected ExtendedRandomisation2 $module;
    protected $project_id;
    protected $rid;
    protected array $attrs;
    protected $isBlinded;
    protected $config_array;
    protected $record;
    protected $strata_field_values;
    protected $group_id;
    protected $next_aid;
    protected $seed = null;
    protected $seedSequence = null;
    protected $ridTotal = 0;
    protected IRandomiserConfiguration $randomiserConfig;

    public static function getClassNameWithoutNamespace() { return str_replace(__NAMESPACE__ . '\\', '', get_called_class()); }
    public static function getConfigOptionName(): string { return static::getClassNameWithoutNamespace(); }
    public static function getConfigOptionLabel(): string { return static::LABEL; }
    public static function getConfigOptionDescription(): string { return static::DESC; }
    abstract protected static function getConfigOptionArray(): array;
    abstract protected static function getConfigOptionMarkupFields($currentSettings=null): string;


    /**
     * Randomise the current record, including any update to the allocation table
     * @param string $record
     * @param array $strata_field_values
     * @param ?int $group_id
     * @return int Return the id of the allocated table entry
     */
    abstract public function randomise();

    /**
     * Update the randomisation state i.e. history of previous randomisations
     * @param array $stratification
     * @param int $allocation
     */
    abstract protected function updateRandomisationState(array $stratification, int $allocation);
    
    /**
     * __construct
     */
    public function __construct(int $randomization_id, array $configArray, ExtendedRandomisation2 $module, string $record, array $strata_field_values, ?int $group_id) {
        $this->project_id = PROJECT_ID;
        $this->module = $module;
        $this->rid = $randomization_id;
        $this->attrs = \Randomization::getRandomizationAttributes($this->rid);
        $this->isBlinded = $this->attrs['isBlinded'];
        $this->seed = intval($module->getProjectSetting('seed'));
        $this->seedSequence = intval($module->getProjectSetting('seed-sequence'));

        $this->config_array = $configArray;

        $this->record = $record;
        $this->strata_field_values = $strata_field_values;
        $this->group_id = $group_id;
        $this->next_aid = $this->readNextAllocationId();

        $this->randomiserConfig = $this->getRandomiserConfig();
    }

    protected function readNextAllocationId() {
        $nextAllocId = \REDCap::getNextRandomizationAllocation($this->project_id, $this->rid, $this->strata_field_values, $this->group_id);
        if ($nextAllocId===false) {
            throw new \Exception("An error occurred in reading the randomization allocation table: rid=$this->rid; record=$this->record; group_id=$this->group_id; fields=".implode(';',array_keys($this->strata_field_values))."; values=".implode(';',array_values($this->strata_field_values)));
        } else if ($nextAllocId==='0') {
            throw new \Exception("Randomization allocation table exhausted: rid=$this->rid; record=$this->record; group_id=$this->group_id; fields=".implode(';',array_keys($this->strata_field_values))."; values=".implode(';',array_values($this->strata_field_values)));
        }
        return $nextAllocId;
    }

    protected function allocateAid($aid) {
        try {
            $result = \REDCap::updateRandomizationTableEntry($this->project_id, $this->rid, $aid, 'is_used_by', $this->record, $this->module->getModuleName());
            $message = '';
        } catch (\Throwable $th) {
            $result = false;
            $message = $th->getMessage();
        }
        return ($result) ? $aid : $message;
    }

    protected function getRandomiserConfig(): IRandomiserConfiguration {
        return new RandomiserConfig(
            static::getConfigOptionName(),
            static::getConfigOptionLabel(),
            static::getConfigOptionDescription(),
            static::getConfigOptionArray()
        );
    }
    
    /**
     * getRandomNumber()
     * Get a random number in the range specified, optionally rounded down to whole number
     * @param int $min
     * @param int $max
     * @param bool $returnFloorInt
     * @return float
     */
    public function getRandomNumber(int $min=0, int $max=1, bool $returnFloorInt=false): float {
        $min = ($min>$max) ? $max : $min;
        $max = ($min>$max) ? $min : $max;

        if (empty($this->seed)) {
            $rn = (float)mt_rand();
        } else {
            // if seed specified then use the nth in sequence corresponding to seed, then increment stored counter
            mt_srand($this->seed);
            $this->seedSequence++;
            for ($i=0; $i < $this->seedSequence; $i++) { 
                $rn = (float)mt_rand();
            }
            $this->module->setProjectSetting('seed-sequence', "{$this->seedSequence}");
        }

        $out = $min + (($max-$min) * $rn/(float)mt_getrandmax());
        return ($returnFloorInt) ? floor($out) : $out;
    }

    protected function moduleLogEvent($message) {
        $randomiser = static::getClassNameWithoutNamespace();
        $description = "$randomiser: $message";
        \REDCap::logEvent($this->module->getModuleName(), $description, '', $this->record, $this->attrs['targetEvent']);
    }

    /**
     * setupPageContent()
     * Config page content 
     *  - select list of applicable randomiser classes
     *  - divs with info and config setting fields for applicable randomiser classes
     */
    public static function setupPageContent($rid) { 
        $currentSettings = array();
        $attrs = \Randomization::getRandomizationAttributes($rid);
        $availableOptions = array('Default'=>\RCView::tt('multilang_75',false));
        $configMarkup = AbstractRandomiser::getConfigOptionMarkup('Default'); // Default "EM not enabled for this rid" message
        
        foreach(self::getRandomiserNames() as $randomiser) {
            $classname = __NAMESPACE__.'\\'.$randomiser;
            if ( ( $attrs['isBlinded'] && $classname::USE_WITH_CONCEALED) ||
                 (!$attrs['isBlinded'] && $classname::USE_WITH_OPEN) )  {
                $availableOptions[$classname::getConfigOptionName()] = $classname::getConfigOptionLabel();
            }
            $configMarkup .= $classname::getConfigOptionMarkup($randomiser, $currentSettings);
        }
        return array($availableOptions, $configMarkup);
    }

    protected static function getConfigOptionMarkup($randomiser, $currentSettings=null): string {
        $form = '';
        if ($randomiser=='Default') {
            $description = AbstractRandomiser::DESC;
        } else {
            try {
                $classname = __NAMESPACE__.'\\'.$randomiser;
                $description = $classname::getConfigOptionDescription();
                $form = $classname::getConfigOptionMarkupFields($currentSettings);
            } catch (\Throwable $th) {
                $description = '';
            }
        }
        $html = \RCView::div(array('id'=>'extrnd-opt-config-'.$randomiser, 'class'=>'extrnd-opt-config extrnd-init-hidden'),
            \RCView::p(array(), $description).
            \RCView::div(array(), $form)
        );
        return $html;
    }

    public static function getRandomiserNames() {
        $names = array();
        $dir = dirname(__FILE__);
        foreach (glob("$dir/*.php") as $path) {
            $filename = array_pop(explode('/',$path));
            $classname = str_replace('.php','',$filename);
            if ($classname!='AbstractRandomiser' && $classname!='RandomiserConfig') {
                require_once $path;
                //if (is_subclass_of($classname, self::getClassNameWithoutNamespace())) {
                $names[] = $classname;
            }
        }
        return $names;
    }

    public static function validateConfigSettings(array $settings) {
        return true;
    }
}
