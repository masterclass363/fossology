<?php
/*
 Copyright (C) 2014, Siemens AG
 Author: Daniele Fognini, Johannes Najjar

 This program is free software; you can redistribute it and/or
 modify it under the terms of the GNU General Public License
 version 2 as published by the Free Software Foundation.

 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License along
 with this program; if not, write to the Free Software Foundation, Inc.,
 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 */
use Fossology\Lib\Dao\ClearingDao;
use Fossology\Lib\Dao\HighlightDao;
use Fossology\Lib\Dao\LicenseDao;
use Fossology\Lib\Dao\UploadDao;
use Fossology\Lib\Util\ChangeLicenseUtility;
use Fossology\Lib\Util\LicenseOverviewPrinter;
use Fossology\Lib\View\HighlightProcessor;
use Fossology\Lib\View\LicenseProcessor;
use Fossology\Lib\View\LicenseRenderer;
use Fossology\Lib\View\Renderer;
use Monolog\Logger;

define("TITLE_clearingView", _("Change concluded License "));

class ClearingView extends FO_Plugin
{
  /**
   * @var UploadDao
   */
  private $uploadDao;
  /**
   * @var LicenseDao
   */
  private $licenseDao;
  /**
   * @var ClearingDao;
   */
  private $clearingDao;
  /**
   * @var LicenseProcessor
   */
  private $licenseProcessor;
  /**
   * @var ChangeLicenseUtility
   */
  private $changeLicenseUtility;
  /**
   * @var LicenseOverviewPrinter
   */
  private $licenseOverviewPrinter;

  /**
   * @var Logger
   */
  private $logger;

  /**
   * @var HighlightDao
   */
  private $highlightDao;

  /**
   * @var HighlightProcessor
   */
  private $highlightProcessor;

  /**
   * @var LicenseRenderer
   */
  private $licenseRenderer;
  /* @var Renderer */
  private $renderer;
  /**
   * @var array colorMapping
   */
  var $colorMapping;

  function __construct()
  {
    $this->Name = "view-license";
    $this->Title = TITLE_clearingView;
    $this->Version = "1.0";
    $this->Dependency = array();
    $this->DBaccess = PLUGIN_DB_WRITE;
    $this->LoginFlag = 0;
    $this->NoMenu = 0;
    parent::__construct();

    global $container;
    $this->licenseDao = $container->get('dao.license');
    $this->uploadDao = $container->get('dao.upload');
    $this->clearingDao = $container->get('dao.clearing');
    $this->licenseProcessor = $container->get('view.license_processor');
    $this->logger = $container->get("logger");

    $this->highlightDao = $container->get("dao.highlight");
    $this->highlightProcessor = $container->get("view.highlight_processor");
    $this->licenseRenderer = $container->get("view.license_renderer");
    $this->renderer = $container->get('renderer');

    $this->changeLicenseUtility = $container->get('utils.change_license_utility');
    $this->licenseOverviewPrinter = $container->get('utils.license_overview_printer');
  }

  /**
   * \brief given a lic_shortname
   * retrieve the license text and display it.
   * @param $licenseShortname
   */
  function ViewLicenseText($licenseShortname)
  {
    $license = $this->licenseDao->getLicenseByShortName($licenseShortname);

    print(nl2br($this->licenseRenderer->renderFullText($license)));
  } // ViewLicenseText()


  /**
   * @param $uploadId
   * @param $uploadTreeId
   * @param $selectedAgentId
   * @param $licenseId
   * @param $highlightId
   * @param $hasHighlights
   * @return string
   */
  private function createLicenseHeader($uploadId, $uploadTreeId, $selectedAgentId, $licenseId, $highlightId, $hasHighlights)
  {
    $output = "";
    $fileTreeBounds = $this->uploadDao->getFileTreeBounds($uploadTreeId);

    if (!$fileTreeBounds->containsFiles())
    {
      $clearingDecWithLicenses = $this->clearingDao->getFileClearings($uploadTreeId);
      $outputTMP = $this->licenseOverviewPrinter->createWrappedRecentLicenseClearing($clearingDecWithLicenses);
      $output .= $outputTMP;

      $output .= $this->createClearingFormAndButtons($uploadId,$uploadTreeId);

      $licenseFileMatches = $this->licenseDao->getFileLicenseMatches($fileTreeBounds);
      $licenseMatches = $this->licenseProcessor->extractLicenseMatches($licenseFileMatches);

      $output .= $this->licenseOverviewPrinter->createLicenseOverview($licenseMatches, $fileTreeBounds->getUploadId(), $uploadTreeId, $selectedAgentId, $licenseId, $highlightId, $hasHighlights);

      $extractedLicenseBulkMatches  = $this->licenseProcessor->extractBulkLicenseMatches($clearingDecWithLicenses);
      $output .= $this->licenseOverviewPrinter->createBulkOverview($extractedLicenseBulkMatches, $fileTreeBounds->getUploadId(), $uploadTreeId, $selectedAgentId, $licenseId, $highlightId, $hasHighlights);
    }
    return $output;
  }

  /**
   * @param $uploadTreeId
   * @param $licenseId
   * @param $selectedAgentId
   * @param $highlightId
   * @return array
   */
  private function getSelectedHighlighting($uploadTreeId, $licenseId, $selectedAgentId, $highlightId)
  {
    $highlightEntries = $this->highlightDao->getHighlightEntries($uploadTreeId, $licenseId, $selectedAgentId, $highlightId);
    if ($selectedAgentId > 0)
    {
      $this->highlightProcessor->addReferenceTexts($highlightEntries);
    } else
    {
      $this->highlightProcessor->flattenHighlights($highlightEntries, array("K", "K "));
    }
    return $highlightEntries;
  }




  /**
   * @param $uploadTreeId
   * @return array of clearingHistory
   */
  private function createClearingHistoryTable($uploadTreeId)
  {
    global $SysConf;
    $user_pk = $SysConf['auth']['UserId'];
    $tableName = "clearingHistoryTable";
    $clearingDecWithLicenses = $this->clearingDao->getFileClearings($uploadTreeId);


    return $this->changeLicenseUtility->printClearingTable($tableName, $clearingDecWithLicenses, $user_pk);
  }




  private function createClearingFormAndButtons($uploadId,$uploadTreeId){

    $text = _("Audit License");
    $output = "<h3>$text</h3>\n";

    /** check if the current user has the permission to change license */
    $permission = GetUploadPerm($uploadId);
    if ($permission >= PERM_WRITE)
    {
      $text = _("You do have write (or above permission) on this upload, thus you can change the license of this file.");
      $output .= "<b>$text</b>";

      $output .= $this->changeLicenseUtility->createChangeLicenseForm($uploadTreeId);
      $output .= $this->changeLicenseUtility->createBulkForm($uploadTreeId);

      $output .= "<br><button type=\"button\" onclick='openUserModal()'>User Decision</button>";
      $output .= "<br><button type=\"button\" onclick='openBulkModal()'>Bulk Recognition</button>";
    } else
    {
      $text = _("Sorry, you do not have write (or above) permission on this upload, thus you cannot change the license of this file.");
      $output .= "<b>$text</b>";
    }

    $output .= "<br>";

    return $output;
  }

  private function createWrappedClearingHistoryTable($uploadId,$uploadTreeId) {
    $permission = GetUploadPerm($uploadId);
     if ($permission >= PERM_WRITE)
    {
      $text = _("Clearing History:");
      $output = "<h3>$text</h3>";
      $output .= $this->createClearingHistoryTable($uploadTreeId);
      return $output;
    }
    return "";
  }


  function OutputOpen($Type, $ToStdout)
  {
    $uploadId = GetParm("upload", PARM_INTEGER);
    if (empty($uploadId))
    {
      return;
    }

    $uploadTreeId = GetParm("item", PARM_INTEGER);
    if (empty($uploadTreeId))
    {
      $parent = $this->uploadDao->getUploadParent($uploadId);
      if (!isset($parent)) return;

      $uploadTreeId = $this->uploadDao->getNextItem($uploadId, $parent);

      header('Location: ?mod=' . $this->Name . Traceback_parm_keep(array("upload", "show")). "&item=$uploadTreeId");
    }

    $uploadTreeTableName= GetUploadtreeTableName($uploadId);
    $uploadEntry = $this->uploadDao->getUploadEntry($uploadTreeId, $uploadTreeTableName );
    if(Isdir($uploadEntry['ufile_mode']) || Iscontainer($uploadEntry['ufile_mode']) ) {
       $parent = $this->uploadDao->getUploadParent($uploadId);
      if (!isset($parent)) return;

      $uploadTreeId = $this->uploadDao->getNextItem($uploadId, $parent);

      header('Location: ?mod=' . $this->Name . Traceback_parm_keep(array("upload", "show")). "&item=$uploadTreeId");
    }
    return parent::OutputOpen($Type, $ToStdout);
  }


  /**
   * \brief display the license changing page
   */
  function Output()
  {
    if ($this->State != PLUGIN_STATE_READY)
    {
      return;
    }
    $licenseShortname = GetParm("lic", PARM_TEXT);
    if (!empty($licenseShortname)) // display the detailed license text of one license
    {
      $this->ViewLicenseText($licenseShortname);
      return;
    }
    $uploadId = GetParm("upload", PARM_INTEGER);
    if (empty($uploadId))
    {
      return;
    }
    $uploadTreeId = GetParm("item", PARM_INTEGER);
    if (empty($uploadTreeId))
    {
      return;
    }
    
    global $Plugins;
    /** @var $view ui_view */
    $view = & $Plugins[plugin_find_id("view")];

    $licenseId = GetParm("licenseId", PARM_INTEGER);
    $folder = GetParm("folder", PARM_INTEGER);
    $selectedAgentId = GetParm("agentId", PARM_INTEGER);
    $highlightId = GetParm("highlightId", PARM_INTEGER);
    $ModBack = GetParm("modback", PARM_STRING);
    if (empty($ModBack))
    {
      $ModBack = "license";
    }
    $highlights = $this->getSelectedHighlighting($uploadTreeId, $licenseId, $selectedAgentId, $highlightId);

    $hasHighlights = count($highlights) > 0;

    /* Get uploadtree table name */
    $uploadTreeTableName = GetUploadtreeTablename($uploadId);

    $output = Dir2Browse('license', $uploadTreeId, NULL, 1, "ChangeLicense", -1, '', '', $uploadTreeTableName) . "\n";

    $Uri = Traceback_uri() . "?mod=view-license";

    $licenseInformation = "";
    $licenseInformation .= $this->createForwardButton($Uri,$folder,$uploadId,$this->uploadDao->getPreviousItem($uploadId, $uploadTreeId), "&lt;" );
    $licenseInformation .= $this->createForwardButton($Uri,$folder,$uploadId,$this->uploadDao->getNextItem($uploadId, $uploadTreeId), "&gt;" );
    $licenseInformation .= "<br>";
    $licenseInformation .= $this->createLicenseHeader($uploadId, $uploadTreeId, $selectedAgentId, $licenseId, $highlightId, $hasHighlights);
    $licenseInformation .= $this->createWrappedClearingHistoryTable($uploadId,$uploadTreeId);
    list($pageMenu,$textView) = $view->getView(NULL, $ModBack, 0, "", $highlights, false, true);

    $legendBox = $this->licenseOverviewPrinter->legendBox($selectedAgentId > 0 && $licenseId > 0);

    $this->renderer->vars['pageMenu'] = $pageMenu;
    $this->renderer->vars['textView'] = $textView;
    $this->renderer->vars['legendBox'] = $legendBox;
    $this->renderer->vars['licenseInformation'] = $licenseInformation;
    $output .= $this->renderer->renderTemplate('ui_view');
    print $output;
  }


  /**
   * @param $Uri
   * @param $folder
   * @param $uploadId
   * @param $forwardUploadTreePk
   * @param $buttonString
   * @return string
   */
  private function createForwardButton($Uri, $folder,$uploadId,  $forwardUploadTreePk, $buttonString) {
    if (isset($forwardUploadTreePk) ) {
      $header = "<b><a class=\"buttonLink\" href=\"$Uri&folder=$folder&upload=$uploadId&item=$forwardUploadTreePk\">$buttonString</a></b>";
    }
    else {
      $header ="";
    }
    return $header;
  }


  /**
 * \brief Customize submenus.
 */
  function RegisterMenus()
  {
    $text = _("Set the concluded licenses for this upload");
    menu_insert("Browse-Pfile::Clearing",0,$this->Name,$text);
    menu_insert("ChangeLicense::View", 5, "view-license" . Traceback_parm_keep(array("show", "format", "page", "upload", "item")), $text);
    menu_insert("View::Audit", 35, $this->Name . Traceback_parm_keep(array("upload", "item", "show")), $text);
    $text = _("View file information");
    menu_insert("ChangeLicense::Info",1, "view_info". Traceback_parm_keep(array("upload","item","format")),$text);
    $text = _("View Copyright/Email/Url info");
    menu_insert("ChangeLicense::Copyright/Email/Url", 1, "copyrightview". Traceback_parm_keep(array("show", "page", "upload", "item")), $text);
    $text = _("Browse by buckets");
    menu_insert("ChangeLicense::Bucket Browser",1,"bucketbrowser". Traceback_parm_keep(array("format","page","upload","item","bp"),$text));
    $text = _("Copyright/Email/URL One-shot, real-time analysis");
    menu_insert("ChangeLicense::One-Shot Copyright/Email/URL", 3, "agent_copyright_once", $text);
    $text = _("Nomos One-shot, real-time license analysis");
    menu_insert("ChangeLicense::One-Shot License", 3, "agent_nomos_once". Traceback_parm_keep(array("format","item")), $text);

    menu_insert("ChangeLicense::[BREAK]",4);

    return 0;
  } // RegisterMenus()
}
$NewPlugin = new ClearingView;