<?php
/**
 * REDCap External Module: Extended Randomisation2
 * @author Luke Stevens, Murdoch Children's Research Institute
 */
if (is_null($module) || !($module instanceof MCRI\ExtendedRandomisation2\ExtendedRandomisation2)) exit;
include APP_PATH_DOCROOT . 'ProjectGeneral/header.php';
$module->batchRandomisationPage(intval($_GET['rid']));
include APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';