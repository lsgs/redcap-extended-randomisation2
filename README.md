# REDCap External Module: Extended Randomization

Luke Stevens, Murdoch Children's Research Institute [https://www.mcri.edu.au/](https://www.mcri.edu.au/)

[https://github.com/lsgs/redcap-extended-randomisation2](https://github.com/lsgs/redcap-extended-randomisation2)
********************************************************************************
## Summary

REDCap external module building on the "Randomization 2.0" features incorporated from v14.7.0 to provide:

* Alternative randomization algorithms beyond REDCap's built-in stratified permuted block randomization (via the `redcap_randomize_record()` hook)
* Batch randomization for randomizing multiple records in sequence
* Option to auto-extend allocation table (add a new entry for the stratum) should stratum entries become exhausted (in some circumstances)

Enable randomization for your project and configure extended options via an additional step ("**STEP 5**") on the Randomization Setup page:

* Biased coin minimization with customizable stratum weightings and allocation ratios
* Random integer in specified range
* Random floating point number 0-1
* Random group selection

Configuration options available using the "Configure" button on the External Modules page include:

* Batch randomization (Development status): select multiple records to randomise sequentially in a batch (e.g. for simulations or cluster randomisation)
* Specify a seed for random number generation (for reproducibility)
* *Admin must enable* Batch randomization (Production status): select multiple records to randomise sequentially in a batch e.g. for cluster randomized trials

## Configuration: External Module Settings
### Recipient for error alert email (optional)
Specify one or more email addresses for people to be notified of any exceptions thrown by the module.

### Enable batch randomization (Development)
(Automatically enabled.)

Adds new "Batch Randomization" tab to Randomization page for users with both "Dashboard" and "Randomize" permissions.

The page will display a list of records in the project that are in a state suitable for being randomized for the current randomization model, i.e. have all releavant stratification factors set, and have not already been randomized. Select a field to sort by (default is record id) and the records to randomize and they will be randomized in sequence. Charts by group for allocaitons overall and for each stratum will be updated dynamically as the process executes and the records randomized.

### Seed for reproducible random number generation (integer, optional)
Specify an integer seed value for the module's random number generator in the project. This fixes the sequence of pseudo-random numbers so that the sequence is reproducible. 

**DO NOT EDIT the seed value once randomizations have commenced**.

### Seed sequence (positive integer)
A counter incremented each time the module generates a random number within the project. Specifies the current location in the sequence of pseudo-random numbers corresponding to the specified seed value.

**DO NOT EDIT the seed sequence** except to delete value or reset to 0 after erasing all project data (although this should happen automaticaly).

### Delay module execution
Unlikely to be necessary, but this option can be checked to have this module executed for each hook as late as possible in the sequence of modules implementing the `redcap_randomize_record()` hook.

## Configuration: Algorithm Options
### Default
Utilize the regular procedure of selecting the next available allocation table entry when randomizing. Not all randomization models in a project need to use one of the alternative algorithms. You may just want to utilize the "Batch Randomization" page, for example, or have one randomization model using (for example) the "Biased coin minimization" option, but a second randomization model in the project use the regular sequential allocation from a stratified list.

*Options:*
* **Auto-extend allocation table**: Option available for both _concealed allocation opnly_. New entries receive an incremented randomization number in the target field.

### Biased Coin Minimization
Dynamic randomization via the biased coin minimization algorithm with customizable strata weighting and group allocation ratios as described in this paper:
> Han, B., Enas, N. H., & McEntegart, D. (2009). Randomization by minimization for unbalanced treatment allocation. *Statistics in medicine, 28*(27), 3329–3346. [https://doi.org/10.1002/sim.3710](https://doi.org/10.1002/sim.3710)

*Options:*
* **Auto-extend allocation table**: Option available for both open and concealed allocation types. New entries receive an incremented randomization number in the alternate (open) or target (concealed) field.
* **Stratification factor weighting**: for each stratification factor specify its relative importance to the minimization calculation. Weighting values must be greater than zero and sum to 1.
* **Group allocation ratio**: 
 * Open allocation: select the allocation ratio for each group. Options are integers 0-10 (and may not all be 0!).
 * Concealed allocation: specify an array of groups/ratios in JSON format, e.g.<br>
 `[{"group":"A","ratio":1},{"group":"B","ratio":1}]`
* **Base assignment probability**: the probability (0-1) that the group calculated to minimize the allocation imbalance will actually be assigned to the randomized record. Typically around 0.7. (0 would mean the preferred group would *never* be assigned; 1 would mean the preferred group would *always* be assigned.)
* **Logging field**: select a notes-type field from the target event into which full logging of the minimization and assignment calculations will be recorded.

*Production Status Configuration Changes:*

Settings for strata weightings, allocation ratios, and base allocation probability are editable in Production status only when no records have yet been randomized using the randomization model. The logging field may be changed.

*Allocation Behaviour:*
* **Open group allocation**: The next available allocation table entry will have its target field (group) updated with the result of the minimization algorithm.
* **Concealed allocation**: The result of the minimization algorithm will be recorded to the alternate target column in the allocation table.

*Allocation Table Generation Options*
REDCap requires that an allocation table is uploaded for each randomization model, and this module works with rather than against this behaviour: the randomization group for the allocated schedule entry is updated with the result produced by the minimization algorithm. There are two options for schedule generation and upload:

1. Generate Sufficient Entries for Each Stratum
Generate a schedule as normal with sufficient entries to cover the maximum number of randomizations expected within each stratum. It does not matter what you load for `randomization_group` because this will be updated for each allocation with the result of the minimization algorithm: it just needs to be a valid category value for the group field (for open allocations). The clarity of the Randomization Dashboard page can be improved by specifying a dummy, placeholder group (e.g. as `0, Placeholder | 1, Intervention | 2, Control`) as the default allocation group in the generated and uploaded randomization schedule, then configuring the dummy, placeholder group to have an allocation ratio of `0`. The Dashboard page will then show unallocated entries all in the "Placeholder" group, and only allocated records in the real allocation categories, as they are assigned.

2. Auto-Extend Allocation Table:
Utilise one of the template allocation tables e.g. Example #2 (all possible combos) as a minimal allocation table, and select the "Auto-extend allocation table" option. Once the small number of allocation table entries are exhausted the module will continue to randomize records by adding a new allocation table entry in the appropriate stratum for each new randomization. Note - with both open and concealed allocation models:
    - Randomization group will be assigned by the minimization algorithm.
    - Randomization number will be incremented from the alphabetically/numerically highest value for the stratum (or remain empty if no values exist).
    - The option may be enabled and disabled in Production.

### Random Integer in Specified Range
Generates a random integer between the specified minimum and maximum values (inclusive).

*Options:*
* **Min**: minimum integer value of range
* **Max**: maximum integer value of range
* **Auto-extend allocation table**: Option available for _concealed allocation only_. New entries receive random integer in target field.

*Allocation Behaviour:*
* **Open group allocation**: Regular group allocation, with the random integer recorded as the randomization number and available via the `[rand-number]` smart variable.
* **Concealed allocation**: The random integer is recorded as the target randomization value, overwriting whatever value was originally uploaded for the entry in the allocation table.

### Random Floating Point Number 0-1
Generates a random floating point number between 0 and 1.

*Options:*
* **Auto-extend allocation table**: Option available for _concealed allocation only_. New entries receive random number in target field.

*Allocation Behaviour:*
* **Open group allocation**: Regular group allocation, with the random number recorded as the randomization number and available via the `[rand-number]` smart variable.
* **Concealed allocation**: The random number is recorded as the target randomization value, overwriting whatever value was originally uploaded for the entry in the allocation table.

### Random Group
Rather than selecting the next available entry from the allocation table in sequence, the record will be assigned to an available allocation table entry at random.

*Options:*
* **Auto-extend allocation table**: Option _NOT available_ for either open or concealed allocation.

*Allocation Behaviour:*
* **Open group allocation**: Record assigned to random allocation table entry for stratum
* **Concealed allocation**: *Not available*

## Screenshots

### Randomization Setup: Additional Step 5

Configure extended options for your randomization models via an additional step ("**STEP 5**") on the Randomization Setup page: 

> <img alt="Randomization Setup: Additional Step 5" src="https://redcap.mcri.edu.au/surveys/index.php?pid=14961&__passthru=DataEntry%2Fimage_view.php&doc_id_hash=ca08ca65a2b626c45f3095c1c161c8961122fafa&id=2078022&s=wAPCzBwv8sJKMRvb&page=file_page&record=6&event_id=47634&field_name=thefile&instance=1" />

### Randomization Models Summary Page

Randomization models configured to use one of the additional allocation options get an indicator icon on the Randomization Summary page:

> <img alt="Randomization Summary Indicator Icons" src="https://redcap.mcri.edu.au/surveys/index.php?pid=14961&__passthru=DataEntry%2Fimage_view.php&doc_id_hash=972c822243ad5ae3a1c96419ef710bf97f140654&id=2078023&s=CTgjBX5Evtdrkbof&page=file_page&record=7&event_id=47634&field_name=thefile&instance=1" />

### Batch Randomization

An additional page is available via Randomization Dashboards that enables batches of records to be randomized in sequence. The record list shows all records in the project that are ready to randomize (i.e. have any necessary stratification saved, and are not yet randomized).

Select the records to randomize and they will be randomized in sequence, with the allocation result and charts updated as the allocations occur.

The Batch Randomization page is available automatically when the module is enabled and the project is in Development status, but must be enabled for Production use in the project by your administrator.

> <img alt="Batch Randomization" src="https://redcap.mcri.edu.au/surveys/index.php?pid=14961&__passthru=DataEntry%2Fimage_view.php&doc_id_hash=ed580a8200cab56d0ce70bbab13ac956d992aa0f&id=2078025&s=JWn4GmNJGjcZY4x6&page=file_page&record=8&event_id=47634&field_name=thefile&instance=1" />