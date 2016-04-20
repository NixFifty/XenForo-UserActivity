<?php

class SV_UserActivity_XenForo_Model_User extends XFCP_SV_UserActivity_XenForo_Model_User
{
    static $SV_UA_TrackRobots = null;

    public function updateSessionActivity($userId, $ip, $controllerName, $action, $viewState, array $inputParams, $viewDate = null, $robotKey = '')
    {
        $userActivityModel = $this->_getSVUserActivityModel();
        $handler = $userActivityModel->getHandler($controllerName);
        if (!empty($handler))
        {
            $requiredKey = $handler[1];
            if (!empty($inputParams[$requiredKey]))
            {
                if (self::$SV_UA_TrackRobots === null)
                {
                    self::$SV_UA_TrackRobots = XenForo_Application::getOptions()->SV_UA_TrackRobots;
                }
                if (!self::$SV_UA_TrackRobots || $robotKey)
                {
                    $user = XenForo_Visitor::getInstance()->toArray();
                    if($userId == $user['user_id'])
                    {
                        $contentType = $handler[0];
                        $userActivityModel->updateSessionActivity($contentType, $inputParams[$requiredKey], $ip, $robotKey, $user);
                    }
                }
            }
        }
        return parent::updateSessionActivity($userId, $ip, $controllerName, $action, $viewState, $inputParams, $viewDate, $robotKey);
    }

    protected function _getSVUserActivityModel()
    {
        return $this->getModelFromCache('SV_UserActivity_Model');
    }
}