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
    public const REDCAP_DEFAULT = 'REDCapDefault';
    public const USE_WITH_OPEN = true;
    public const USE_WITH_CONCEALED = true;
    public const EXTEND_ALLOC_TABLE_ENTRY_OPEN = false;
    public const EXTEND_ALLOC_TABLE_ENTRY_CONCEALED = false;
    public const EXTEND_CB_CHECKED = 1, EXTEND_CB_UNCHECKED = 0, EXTEND_CB_DISABLED = -1;
    protected const LABEL = '-';
    protected const DESC = '-';
    protected ExtendedRandomisation2 $module;
    protected $project_id;
    protected $project_status;
    protected $rid;
    protected array $attrs;
    protected $isBlinded;
    protected $config_current_index;
    protected $config_current_randomiser_type;
    protected $config_current_settings_array;
    protected $randomisation_state = null;
    protected $record;
    protected $strata_field_values;
    protected $group_id;
    protected $next_aid;
    protected $seed = null;
    protected $seedSequence = null;
    protected $ridTotal = 0;
    protected $extend_allocation_table = false;
    public static $ProdEditableSettings = array(); // any settings that are editable in prod

    public static function getClassNameWithoutNamespace() { return str_replace(__NAMESPACE__ . '\\', '', get_called_class()); }
    public function getConfigOptionName(): string { return static::getClassNameWithoutNamespace(); }
    public function getConfigOptionLabel(): string { return static::LABEL; }
    public function getConfigOptionDescription(): string { return static::DESC; }
    public function getNextAid() { return $this->next_aid; }
    public function canExtendTable() { return ($this->isBlinded) ? static::EXTEND_ALLOC_TABLE_ENTRY_CONCEALED : static::EXTEND_ALLOC_TABLE_ENTRY_OPEN; }
    public function getExtendAllocationTableOption() { 
        if ($this->canExtendTable() && $this->extend_allocation_table) {
            return static::EXTEND_CB_CHECKED;
        } else if ($this->canExtendTable() && !$this->extend_allocation_table) {
            return static::EXTEND_CB_UNCHECKED;
        } else {
            return static::EXTEND_CB_DISABLED;
        }
    }
    abstract protected function getConfigOptionMarkupFields(): string;

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
    
    public static function getRandomiserNames() {
        $names = array(self::REDCAP_DEFAULT);
        $dir = dirname(__FILE__);
        foreach (glob("$dir/*.php") as $path) {
            $filename = array_pop(explode('/',$path));
            $classname = str_replace('.php','',$filename);
            if ($classname!='AbstractRandomiser' && $classname!=self::REDCAP_DEFAULT) {
                require_once $path;
                //if (is_subclass_of($classname, self::getClassNameWithoutNamespace())) {
                $names[] = $classname;
            }
        }
        return $names;
    }

    /**
     * __construct
     * Create a new randomiser intitialised with project/randomisation/config information (but not record context)
     */
    public function __construct(int $randomization_id, ExtendedRandomisation2 $module, bool $applyConfig=true) {
        global $Proj;
        $this->project_id = PROJECT_ID;
        $this->project_status = $Proj->project['status'];
        $this->module = $module;
        $this->rid = $randomization_id;
        $this->attrs = $this->module->getRandAttrs($this->rid);
        $this->isBlinded = $this->attrs['isBlinded'];
        $this->seed = intval($module->getProjectSetting('seed'));
        $this->seedSequence = intval($module->getProjectSetting('seed-sequence'));

        list($thisRidKey, $randomiserType, $randConfigText, $randExtend) = $module->getRandConfigSettings($randomization_id);
        $this->config_current_index = $thisRidKey;
        $this->config_current_randomiser_type = $randomiserType;
        $this->config_current_settings_array = \json_decode($randConfigText, true) ?? array();

        $this->extend_allocation_table = $this->canExtendTable() && $randExtend;

        if ($applyConfig && !is_array($this->config_current_settings_array)) throw new \Exception("Could not read module config for randomization id $randomization_id, '$randomiserType'");
    }

    public function applySavedConfig(): void {
        if (is_array($this->config_current_settings_array) && !empty($this->config_current_settings_array)) {
            $configure = $this->validateConfigSettings($this->config_current_settings_array);
            if ($configure!==true) {
                throw new \Exception("Error in randomiser config: $configure");
            }
        }
    }

    /**
     * initialiseRecordRandomiser()
     * Initialise the randomiser with record data ready to perform randomisation
     * @param string $record
     * @param array $strata_field_values
     * @param ?int $group_id
     * @return void
     */
    public function initialiseRecordRandomiser(string $record, array $strata_field_values, ?int $group_id): void {
        $this->record = $record;
        $this->strata_field_values = $strata_field_values;
        $this->group_id = $group_id;
        $this->next_aid = $this->readNextAllocationId();

        if ($this->next_aid==='0' && $this->extend_allocation_table) {
            $this->next_aid = $this->insertAllocationId($strata_field_values, $group_id);
        }
    }

    protected function readNextAllocationId() {
        if (!isset($this->record)) throw new \Exception("An error occurred in reading the randomization allocation table: no record specified.");
        $groupName = (is_null($this->group_id)) ? null : \REDCap::getGroupNames(true, $this->group_id);
        $nextAllocId = \REDCap::getNextRandomizationAllocation($this->project_id, $this->rid, $this->strata_field_values, $groupName);
        if ($nextAllocId===false) {
            throw new \Exception("An error occurred in reading the randomization allocation table: rid=$this->rid; record=$this->record; group_id=$this->group_id; fields=".implode(';',array_keys($this->strata_field_values))."; values=".implode(';',array_values($this->strata_field_values)));
        } else if ($nextAllocId==='0') {
            // Randomization allocation table exhausted
        }
        return $nextAllocId;
    }

    protected function allocateAid($aid) {
        try {
            if (!isset($this->record)) throw new \Exception("An error occurred updating the randomization allocation table: no record specified.");
            $result = \REDCap::updateRandomizationTableEntry($this->project_id, $this->rid, $aid, 'is_used_by', $this->record, $this->module->getModuleName());
            $message = '';
        } catch (\Throwable $th) {
            $result = false;
            $message = $th->getMessage();
        }
        return ($result) ? $aid : $message;
    }

    protected function insertAllocationId(array $strata_field_values, ?int $group_id): int {
        if (!$this->extend_allocation_table) throw new \Exception('Extending allocation table not permitted');

		// Validate fields
		$criteriaFields = \Randomization::getRandomizationFields($this->rid, false, true, false, $this->project_id); // events=false; criteriafields=true; targetfield=false
		if (count($strata_field_values) != count($criteriaFields)) throw new \Exception('Could not extend allocation table: incomplete stratification');
		foreach (array_keys($strata_field_values) as $field) {
			if (!in_array($field, $criteriaFields)) throw new \Exception("Could not extend allocation table: incomplete stratification ($field missing)");
		}
		// Validate group_id
        if ($this->attrs['group_id'] == 'DAG') {
		    $group_id = intval($group_id ?? 0);
            if ($group_id < 1) throw new \Exception("Could not extend allocation table: incomplete stratification (group_id missing)");
        } else {
            $group_id = null;
        }

        // Create sqls for max randno and insert
		$sqlwhere = "WHERE `rid`=? AND `project_status`=? AND `group_id`" . ($group_id > 0 ? "=$group_id" : " IS NULL");
        $selectvalues = array($this->rid, $this->project_status);
		$sqlinsert = " INSERT INTO redcap_randomization_allocation (rid, project_status, group_id ";
		$sqlvalues = " VALUES ( ?, ?, ?";
        $insertvalues = array($this->rid, $this->project_status, $group_id);
        $logdisplay = "rid = {$this->rid} \n project_status = {$this->project_status} \n group_id = {$group_id}";

        foreach ($criteriaFields as $col => $field) {
            $val = $this->module->escape($strata_field_values[$field]);
			$sqlwhere .= " AND `$col`= ?";
    		$sqlvalues .= ", ?";
            $selectvalues[] = $val;
            $sqlinsert .= ", $col";
            $insertvalues[] = $val;
            $logdisplay .= " \n $col = $val";
		}

        $sqlselect = "SELECT `aid`,`target_field`,`target_field_alt` FROM redcap_randomization_allocation $sqlwhere ORDER BY `aid` DESC LIMIT 1";
		$q = $this->module->query($sqlselect, $selectvalues);
		if (db_num_rows($q) > 0) {
            $lastStratumRandnoCol = ($this->isBlinded) ? 'target_field' : 'target_field_alt';
			$lastStratumRandno = $q->fetch_assoc()[$lastStratumRandnoCol];
            $randno = $this->incrementRandnoValue($lastStratumRandno);
            $sqlinsert .= ", $lastStratumRandnoCol";
    		$sqlvalues .= ", ?";
            $insertvalues[] = $randno;
            $logdisplay .= " \n $lastStratumRandnoCol = $randno";
		}

        $q = $this->module->query("$sqlinsert ) $sqlvalues )", $insertvalues); 
        if($error = db_error()){
            throw new \Exception('Could not extend allocation table: INSERT failed with error "'.$error.'"');
        }
        $new_aid = db_insert_id();
        
        \Logging::logEvent(
            "$sqlinsert ) $sqlvalues ) [".implode(',',$insertvalues).']', // sql_log
            'redcap_randomization_allocation', // table / object_type
            'MANAGE', // event
            $new_aid, // pk
            $logdisplay, // display / data_values
            $this->module->getModuleName().': extend allocation table' // descrip
        ); 

        return $new_aid;
    }

    /**
     * incrementRandnoValue()
     * Increment trailing digits on a string: "R1-1 -> R1-2"
     * If param is empty or has no trailing digits then return empty string.
     * @param string $value
     * @return string $value_with_increment
     */
    protected function incrementRandnoValue(string $value): string {
        if (empty($value)) return '';
        // increment trailing digits
        $matches = array();
        if (preg_match('/(.*)(\d+)$/', $value, $matches)) {
            $stem = $matches[1];
            $intpart = $matches[2];
            $randno = $stem.(1+intval($intpart));
        } else {
            $randno = '';
        }
        return $randno;
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

    public function getConfigOptionMarkup(int $extendOption): string {
        $randomiserName = $this->getConfigOptionName();
        $form = '';
        try {
            $description = $this->getConfigOptionDescription();
            $form = $this->getConfigOptionMarkupFields();
        } catch (\Throwable $th) {
            $description = '';
        }
        $html = static::makeConfigOptionMarkup($randomiserName, $description, $form, $extendOption);
        return $html;
    }

    protected static function makeConfigOptionMarkup($randomiserName, $description, $settingsForm, $extendOption): string {
        
            $cbExtendProps = array('type'=>'checkbox','name'=>'extrnd-extend-table','value'=>'1');
            $popoverContent = "<p>Adding a new allocation table entry when needed (no entry available in stratum) <strong>is possible</strong> for this randomization.</p>";
            switch ($extendOption) {
                case static::EXTEND_CB_CHECKED: 
                    $cbExtendProps['checked'] = 'checked';
                    break;
                case static::EXTEND_CB_UNCHECKED: 
                    break;
                case static::EXTEND_CB_DISABLED: 
                default:
                    $cbExtendProps['disabled'] = 'disabled';
                    $cbExtendProps['title'] = 'Not available for this type of model and randomization option';
                    $popoverContent = "<p>Adding a new allocation table entry when needed (no entry available in stratum) is <strong>not possible</strong> for this randomization.</p>";
                    $labelClass = 'text-muted font-weight-normal';
                    break;
            }
            $popoverContent .= '<ul><li><i class="fas fa-envelope-open text-success mr-1"></i>'.\RCView::tt('random_161').': Can add new allocation table entries only with dynamic group allocation (minimization).</li>';
            $popoverContent .= '<li><i class="fas fa-envelope text-danger mr-1"></i>'.\RCView::tt('random_162').': Can add new allocation table entries when needed. Randomization number will be incremented from stratum maximum.</li></ul>';
            $extendTableMarkup = 
                \RCView::span(array('class'=>"$labelClass mr-2", 'style'=>'display:inline-block;min-width:120px;'), \RCView::span(array(),"Auto-extend allocation table?")) . 
                \RCView::input($cbExtendProps).
                \RCView::a(array('class'=>'extrnd-auto-extend-info ml-2', 'style'=>'color:#006', 'data-bs-toggle'=>'popover', 'data-bs-trigger'=>'focus', 'data-bs-placement'=>'top', 'data-bs-title'=>'Auto-Extend Allocation Table', 'data-bs-content'=>$popoverContent, 'role'=>'button', 'tabindex'=>'0'),
                    '<i class="fa-solid fa-info-circle"></i>'
                );

        $container = \RCView::div(array('id'=>'extrnd-opt-config-'.$randomiserName, 'class'=>'extrnd-opt-config extrnd-init-hidden'),
            \RCView::p(array(), $description).
            \RCView::p(array(), $extendTableMarkup).
            \RCView::div(array(), $settingsForm)
        );
        return $container;
    }

    public function validateConfigSettings(array &$settings) {
        return true;
    }

    /**
     * isConfigEditable
     * Configuration will not be editable once a record is randomised in Production
     * - static::$ProdEditableSettings is array of settings that are allowed to be edited in prod, but this method will still return false
     * @return bool
     */
    public function isConfigEditable() : bool {
        $sql = "select count(*) as n_prod_rand from redcap_randomization_allocation where rid = ? and is_used_by is not null and project_status = 1 group by rid";
        $result = $this->module->query($sql, [$this->rid]);
        if ($result->num_rows === 0) return true;
        $n_prod_rand = $result->fetch_assoc()['n_prod_rand'];
        return $n_prod_rand === 0; // editable when 0 prod rand
    }
}
