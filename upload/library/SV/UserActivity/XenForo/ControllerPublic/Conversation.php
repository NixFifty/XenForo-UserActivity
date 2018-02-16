<?php

/** @noinspection PhpIncludeInspection */
include_once('SV/UserActivity/UserActivityInjector.php');
class SV_UserActivity_XenForo_ControllerPublic_Conversation extends XFCP_SV_UserActivity_XenForo_ControllerPublic_Conversation
{
    protected $activityInjector = [
        'controller' => 'XenForo_ControllerPublic_Conversation',
        'type'       => 'conversation',
        'id'         => 'conversation_id',
        'actions'    => ['view'],
        'activeKey'  => 'conversation',
    ];
    use UserActivityInjector;
}
