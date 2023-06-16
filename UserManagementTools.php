<?php
/**
 * REDCap External Module: User Management Tools
 * @author Luke Stevens, Murdoch Children's Research Institute
 */
namespace MCRI\UserManagementTools;

use ExternalModules\AbstractExternalModule;

class UserManagementTools extends AbstractExternalModule
{
    private const TITLE_BLOCK='Project Access Restricted';
    private const TITLE_WARN='Inappropriate Permission Configuration';
    private const DEFAULT_MESSAGE_BLOCK='You are not permitted access to this project in a role that confers "User Rights" or "DAGs" permissions.';
    private const DEFAULT_MESSAGE_WARN='Your user account should not have "User Rights" or "DAGs" permissions.';
    protected $user = null;
    protected $username = null;

    public function redcap_every_page_top($project_id) {
        if (empty($project_id)) return;
        if (!defined('USERID')) return;
        $this->user = $this->getUser();
        $this->username = $this->user->getUsername();
        if ($this->username==="[survey_respondent]") return;
        if ($this->hasPagePermission()) return;
        $this->restrictPageAccess();
    }

    /**
     * hasPermission()
     * Only Institutional user accounts are permitted User Rights and DAGs access to projects 
     * @return bool 
     */
    protected function hasPagePermission($username=null, $userRight=null) {
        $username = $username ?? $this->username;
        $permitted = false;
        $rights = $this->getRights($username);

        if (is_null($userRight)) {
            // any/all project pages - block/warn if non-Institutional user account with admin rights
            if ($rights['user_rights']=='0' && $rights['data_access_groups']=='0') $permitted = true;

            $overrideList = $this->getSystemSetting('permit-as-project-admin');
            if (preg_match("/\b$username\b/", $overrideList)) $permitted = true; // username is in permitted list

            $q = $this->query("select 1 from redcap_user_allowlist where username=? limit 1", [$username]);
            if (db_num_rows($q)>0) $permitted = true; // user is in allow list
        } else {
            // does specified user have the specified access permission?
            $permitted = (bool)$rights[$userRight];
        }

        return $permitted;
    }

    /**
     * restrictPageAccess()
     * Include content in page that prevents access
     */
    protected function restrictPageAccess() {
        $restrictOption = $this->getSystemSetting('restriction-option');
        echo $this->$restrictOption();
	    echo "<script type='text/javascript'>";
        echo "    $(document).ready(function(){";
        if ($restrictOption=='restrictBlock') echo "        $('#center').children().not('#subheader,#south').css('visibility','hidden');";
        echo "        $('#UserManagementTools_Message').insertAfter('#subheader').show();";
        echo "    });";
        echo "</script>";
    }

    protected function restrictBlock() {
        $message = \REDCap::filterHtml($this->getSystemSetting('display-message'));
        $message = (empty($message)) ? "<h5><i class='fas fa-exclamation-triangle mr-1'></i>".static::TITLE_BLOCK."</h5><p>".static::DEFAULT_MESSAGE_BLOCK."</p>" : $message;
        $message = "<div id='UserManagementTools_Message' style='display:none;' class='red'>$message<p>";
        $message .= $this->getProjectAdminsList();
        $message .= "</p></div>";
        return $message;
    }

    protected function restrictWarn() {
        $message = \REDCap::filterHtml($this->getSystemSetting('display-message'));
        $message = (empty($message)) ? "<h5><i class='fas fa-exclamation-triangle mr-1'></i>".static::TITLE_WARN."</h5><p>".static::DEFAULT_MESSAGE_WARN."</p>" : $message;
        $message = "<div id='UserManagementTools_Message' style='display:none;' class='yellow'>$message<p>";
        $message .= $this->getProjectAdminsList();
        $message .= "</p></div>";
        return $message;
    }

    /**
     * getProjectAdminsList()
     * @return string
     */
    protected function getProjectAdminsList() {
        $list = '';
        $projectAdmins = $this->getProjectAdmins();
        if (count($projectAdmins)) {
            $list = "Contact one of these project team members to set your permissions appropriately:<ul>";
            foreach ($projectAdmins as $admin) {
                $name = $admin->getUsername();
                $email = $admin->getEmail();
                $subj = "Project User Rights (pid=".$this->getProject()->getProjectId().")";
                $body = \urlencode("");
                $list .= "<li>$name <a href='mailto:$email?subject=$subj&body=$body'>$email</a></li>";
            }
            $list .= "</ul>";
        } else {
            $list = "Contact the REDCap administrator for assistance: ".\REDCap::filterHtml($GLOBALS['project_contact_email']);
        }
        return $list;
    }

    /**
     * getProjectAdmins()
     * @return Array
     */
    protected function getProjectAdmins() {
        $admins = array();
        foreach ($this->getProject()->getUsers() as $user) {
            if (
                    $this->hasPagePermission($user->getUsername(), 'user_rights') &&
                    $this->hasPagePermission($user->getUsername())
                ) {
                $admins[] = $user;
            }
        }
        return $admins;
    }

    /**
     * redcap_user_rights($project_id)
     * Highlight users with inappropriate permissions
     */
    public function redcap_user_rights($project_id) {
        $bad = array();
        foreach ($this->getProject()->getUsers() as $user) {
            if (!$this->hasPagePermission($user->getUsername())) $bad[] = $user->getUsername();
        }
        if (count($bad) > 0) {
            echo '<div id="UserManagementTools_Info" class="yellow mb-1" style="max-width:850px;display:none;"><h6><i class="fas fa-exclamation-triangle mr-1"></i>'.static::TITLE_WARN.'</h6><p class="my-0">External users in project admin roles: '.implode(', ',$bad).'</p></div>';
            ?>
            <script type="text/javascript">
              $(document).ready(function(){
                var badusers = JSON.parse('<?=\json_encode($bad)?>');
                badusers.forEach(function(e){
                    $('div.userNameLinkDiv a[userid='+e+']').removeClass('text-primaryrc').css('color', '#f00').attr('title', 'Inappropriate permissions for this user!');
                });
                $('#UserManagementTools_Info').insertBefore('#user_rights_roles_table').slideDown();
              });
            </script>
            <?php
        }
    }
}