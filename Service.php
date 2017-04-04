<?php

class Service {

    private $api;
    private $apiKey;
    private $listId, $lists;

    private function __construct() {
        require_once  'autoload.php';

        $this->apiKey = "KEY";
        $this->listId ="LISTID;

        if ($this->apiKey != '') {
            $this->api = new Mailchimp($this->apiKey);

            $this->lists = new Mailchimp_Lists($this->api);
        }
    }

    public function subscribeMember($firstName, $lastName, $userEmail) {
        if (empty($this->apiKey) || empty($this->listId)) {
            return false;
        }

        $optin = OW::getConfig()->getValue('mailchimp', 'enableDoubleOptin') == '1' ? true : false;
        $welcomeMail = OW::getConfig()->getValue('mailchimp', 'enableWelcomeLetter') == '1' ? true : false;

        try {
            $this->lists->subscribe($this->listId, $userEmail, array(
                'FNAME' => $firstName,
                'LNAME' => $lastName
                    ), 'html', $optin, true, true, $welcomeMail);
        } catch (Exception $e) {
            return false;
        }

        return true;
    }

    public function isKeyValid($apiKey) {

        try {
            $mc = new Mailchimp($apiKey);
            $helper = new Mailchimp_Helper($mc);

            $result = $helper->ping();
            if ($result['msg'] == "Everything's Chimpy!") {
                return true;
            } else {
                return false;
            }
        } catch (Exception $e) {
            return false;
        }
    }

    public function unsubscribeMember($userEmail) {
        if (empty($this->apiKey) || empty($this->listId)) {
            return false;
        }

        try {
            $this->lists->unsubscribe($this->listId, $userEmail);
        } catch (Exception $e) {
            return false;
        }
    }

    public function subscribeExistingMembers($listId, $ignoreUnsubscribe, $unsubscribeOtherLists, $userRoles) {
        if (empty($this->apiKey) || empty($listId)) {
            return false;
        }

        $join = '';
        $where = '';
        $batch = array();

        if (OW::getConfig()->getValue('base', 'mandatory_user_approve') == 1) {
            $join .= " LEFT JOIN `" . (BOL_UserApproveDao::getInstance()->getTableName()) . "` AS `disapprov`
                        ON (`u`.`id` = `disapprov`.`userId`) ";
            $where .= " AND  ( `disapprov`.`id` IS NULL ) ";
        }

        if (OW::getConfig()->getValue('base', 'confirm_email') == 1) {
            $where .= " AND  u.emailVerify = 1 ";
        }

        if ($ignoreUnsubscribe !== true) {
            $join .= " LEFT JOIN `" . (BOL_PreferenceDataDao::getInstance()->getTableName()) . "` AS `preference`
                    ON (`u`.`id` = `preference`.`userId` AND `preference`.`key` = 'mass_mailing_subscribe') ";
            $where .= " AND  ( `preference`.`value` = 'true' OR `preference`.`id` IS NULL ) ";
        }

        if (!empty($userRoles) && is_array($userRoles)) {
            $join .= " INNER JOIN `" . (BOL_AuthorizationUserRoleDao::getInstance()->getTableName()) . "` AS `userRole`
                    ON (`u`.`id` = `userRole`.`userId`)
                    INNER JOIN `" . (BOL_AuthorizationRoleDao::getInstance()->getTableName()) . "` AS `role`
                        ON (`userRole`.`roleId` = `role`.`id`) ";
            $where .= " AND  ( `role`.`name` IN ( " . OW::getDbo()->mergeInClause($userRoles) . " ) ) ";
        }

        $query = "
            SELECT  DISTINCT `u`.*
            FROM `" . BOL_UserDao::getInstance()->getTableName() . "` AS `u`
            LEFT JOIN `" . (BOL_UserSuspendDao::getInstance()->getTableName()) . "` AS `suspend` ON ( u.id = `suspend`.userId )" . $join . "
            WHERE 1 " . $where . " AND u.id > " . $startId . " AND`suspend`.id IS NULL
            LIMIT 0," . $accountsCount;

        $users = OW::getDbo()->queryForObjectList($query, BOL_UserDao::getInstance()->getDtoClassName());

        foreach ($users as $user) {

            if ($unsubscribeOtherLists == '1') {
                $this->unsubscribeAllLists($user->email, $listId);
            }

            $names = preg_split('/\s+(?=[^\s]+$)/', BOL_UserService::getInstance()->getDisplayName($user->id), 2);

            $batch[] = array(
                'EMAIL' => $user->email,
                'FNAME' => isset($names[0]) ? $names[0] : ' ',
                'LNAME' => isset($names[1]) ? $names[1] : ' '
            );
        }

        $lastUser = end($users);

        if (count($batch) == 0) {
            return false;
        }

        $optin =  false;
        $up_exist = true; // yes, update currently subscribed users
        $replace_int = false; // no, add interest, don't replace

        try {
            $vals = $this->lists->batchSubscribe($listId, $batch, $optin, $up_exist, $replace_int);
            return $vals;
        } catch (Exception $e) {
            return false;
        }
    }

    public function getAllList() {
        if (empty($this->apiKey)) {
            return array();
        }

        try {
            $retval = $this->lists->getList();
        } catch (Exception $e) {
            return false;
        }

        if (isset($retval['errors']['code'])) {
            return $retval['errors']['code'];
        } else {
            $listDetails = array();

            foreach ($retval['data'] as $list) {
                $id = $list['id'];
                $listDetails[$id]['id'] = $id;
                $listDetails[$id]['name'] = $list['name'];
                $listDetails[$id]['webId'] = $list['web_id'];
                $listDetails[$id]['memberCount'] = $list['stats']['member_count'];
                $listDetails[$id]['unsubscribeCount'] = $list['stats']['unsubscribe_count'];
                $listDetails[$id]['cleanedCount'] = $list['stats']['cleaned_count'];
            }

            return $listDetails;
        }
    }

}
