<?php

class SV_UserActivity_Model extends XenForo_Model
{
    protected static $handlers = array();
    protected static $logging = true;

    public function getSampleInterval()
    {
        return 30;
    }

    public function supresssLogging()
    {
        self::$logging = false;
    }

    public function isLogging()
    {
        return self::$logging;
    }


    public function registerHandler($controllerName, $contentType, $contentIdField)
    {
        self::$handlers[$controllerName] = array($contentType, $contentIdField);
    }

    public function getHandler($controllerName)
    {
        if (empty(self::$handlers[$controllerName]))
        {
            return false;
        }
        return self::$handlers[$controllerName];
    }

    public function insertUserActivityIntoViewResponse($controllerName, &$response)
    {
        if ($response instanceof XenForo_ControllerResponse_View)
        {
            $handler = $this->getHandler($controllerName);
            if (empty($handler))
            {
                return;
            }
            $contentType = $handler[0];
            $contentIdField = $handler[1];
            if (empty($response->params[$contentType][$contentIdField]))
            {
                return;
            }

            $visitor = XenForo_Visitor::getInstance();
            if (!$visitor->hasPermission('RainDD_UA_PermissionsMain', 'RainDD_UA_ThreadViewers'))
            {
                return;
            }
            $response->params['UA_UsersViewing'] = $this->getUsersViewing($contentType, $response->params[$contentType][$contentIdField], $visitor->toArray());
            $response->params['UA_ViewerPermission'] = !empty($response->params['UA_UsersViewing']);
            $response->params['UA_ContentType'] = new XenForo_Phrase($contentType);
        }
    }

    public function GarbageCollectActivity(array $data, $targetRunTime = null)
    {
        $registry = $this->_getDataRegistryModel();
        $cache = $this->_getCache(true);
        if (!method_exists($registry, 'getCredis') || !($credis = $registry->getCredis($cache)))
        {
            // do not have a fallback
            return;
        }

        $options = XenForo_Application::getOptions();
        $onlineStatusTimeout = $options->onlineStatusTimeout * 60;
        // we need to manually expire records out of the per content hash set if they are kept alive with activity
        $datakey = Cm_Cache_Backend_Redis::PREFIX_KEY. $cache->getOption('cache_id_prefix') . "activity.";

        $end = XenForo_Application::$time - $onlineStatusTimeout;
        $end = $end - ($end  % $this->getSampleInterval());

        // indicate to the redis instance would like to process X items at a time.
        $count = 100;
        // prevent looping forever
        $loopGuard = 10000;
        // find indexes matching the pattern
        $cursor = empty($data['cursor']) ? null : $data['cursor'];
        $s = microtime(true);
        do
        {
            $keys = $credis->scan($cursor, $datakey ."*", $count);
            $loopGuard--;
            if ($keys === false)
            {
                break;
            }
            $data['cursor'] = $cursor;

            // the actual prune operation
            foreach($keys as $key)
            {
                $credis->zremrangebyscore($key, 0, $end);
            }

            $runTime = microtime(true) - $s;
            if ($targetRunTime && $runTime > $targetRunTime)
            {
                break;
            }
            $loopGuard--;
        }
        while($loopGuard > 0 && !empty($cursor));

        if (empty($cursor))
        {
            return false;
        }
        return $data;
    }

    const LUA_IFZADDEXPIRE_SH1 = 'dc1d76eefaca2f4ccf848a6ed7e80def200ac7b7';

    public function updateSessionActivity($contentType, $contentId, $ip, $robotKey, array $viewingUser = null)
    {
        $this->standardizeViewingUserReference($viewingUser);

        $score = XenForo_Application::$time - ( XenForo_Application::$time  % $this->getSampleInterval());
        $data = array
        (
            'user_id' => $viewingUser['user_id'],
            'username' => $viewingUser['username'],
            'visible' => $viewingUser['visible'] && $viewingUser['activity_visible'] ? 1 : null,
            'robot'  => empty($robotKey) ? null : 1,
            'display_style_group_id' => null,
            'gender' => null,
            'avatar_date' => null,
            'gravatar' => null,
            'ip' => null,
        );

        $options = XenForo_Application::getOptions();
        if ($viewingUser['user_id'])
        {
            if ($options->RainDD_UA_ThreadViewType == 0)
            {
                $data['display_style_group_id'] = $viewingUser['display_style_group_id'];
            }
            else if ($options->RainDD_UA_ThreadViewType == 1)
            {
                $data['gender'] = $viewingUser['gender'];
                $data['avatar_date'] = $viewingUser['avatar_date'];
                $data['gravatar'] = $viewingUser['gravatar'];
            }
            else
            {
                // unknown display type
                return;
            }
        }
        else
        {
            $data['ip'] = $ip;
        }

        $registry = $this->_getDataRegistryModel();
        $cache = $this->_getCache(true);
        if (!method_exists($registry, 'getCredis') || !($credis = $registry->getCredis($cache)))
        {
            // do not have a fallback
            return;
        }
        $useLua = method_exists($registry, 'useLua') && $registry->useLua($cache);

        // encode the data
        $raw = implode("\n", $data);

        // record keeping
        $key = Cm_Cache_Backend_Redis::PREFIX_KEY. $cache->getOption('cache_id_prefix') . "activity.{$contentType}.{$contentId}";
        $onlineStatusTimeout = $options->onlineStatusTimeout * 60;

        if ($useLua)
        {
            $ret = $credis->evalSha(self::LUA_IFZADDEXPIRE_SH1, array($key), array($score, $raw, $onlineStatusTimeout));
            if ($ret === null)
            {
                $script =
                    "local c = tonumber(redis.call('zscore', KEYS[1], ARGV[2])) ".
                    "local n = tonumber(ARGV[1]) ".
                    "local retVal = 0 ".
                    "if c == nil or n > c then ".
                      "retVal = redis.call('ZADD', KEYS[1], n, ARGV[2]) ".
                    "end ".
                    "redis.call('EXPIRE', KEYS[1], ARGV[3]) ".
                    "return retVal ";
                $credis->eval($script, array($key), array($score, $raw, $onlineStatusTimeout));
            }
        }
        else
        {
            $credis->pipeline()->multi();
            // O(log(N)) for each item added, where N is the number of elements in the sorted set.
            $credis->zadd($key, $score, $raw);
            $credis->expire($key, $onlineStatusTimeout);
            $credis->exec();
        }
    }

    const CacheKeys = array
    (
        'user_id',
        'username',
        'visible',
        'robot',
        'display_style_group_id',
        'gender',
        'avatar_date',
        'gravatar',
        'ip',
    );

    public function getUsersViewing($contentType, $contentId, array $viewingUser = null)
    {
        $this->standardizeViewingUserReference($viewingUser);

        $memberCount = 1;
        $guestCount = 0;
        $robotCount = 0;
        $records = array($viewingUser);

        $registry = $this->_getDataRegistryModel();
        $cache = $this->_getCache(true);
        if (!method_exists($registry, 'getCredis') || !($credis = $registry->getCredis($cache, true)))
        {
            // do not have a fallback
            return null;
        }
        else
        {
            $key =  Cm_Cache_Backend_Redis::PREFIX_KEY. $cache->getOption('cache_id_prefix') . "activity.{$contentType}.{$contentId}";

            $options = XenForo_Application::getOptions();
            $start = XenForo_Application::$time  - $options->onlineStatusTimeout * 60;
            $start = $start - ($start  % $this->getSampleInterval());
            $end = XenForo_Application::$time + 1;
            $onlineRecords = $credis->zrevrangebyscore($key, $end, $start, array('withscores' => true));
            // check if the activity counter needs pruning
            if ($options->UA_pruneChance > 0 && mt_rand() < $options->UA_pruneChance)
            {
                $credis = $registry->getCredis($cache, false);
                if ($credis->zcard($key) >= count($onlineRecords) * $options->UA_fillFactor)
                {
                    // O(log(N)+M) with N being the number of elements in the sorted set and M the number of elements removed by the operation.
                    $credis->zremrangebyscore($key, 0, $start - 1);
                }
            }
        }

        $cutoff = $options->SV_UA_Cutoff;
        $memberVisibleCount = 1;

        if(is_array($onlineRecords))
        {
            $seen = array($viewingUser['user_id'] => true);
            $bypassUserPrivacy = $this->_getUserModel()->canBypassUserPrivacy($null, $viewingUser);
            $sampleInterval = $this->getSampleInterval();

            foreach($onlineRecords as $rec => $score)
            {
                $data = explode("\n", $rec);
                $rec = @array_combine(self::CacheKeys, $data);
                if (empty($rec))
                {
                    continue;
                }
                if ($rec['user_id'])
                {
                    if (empty($seen[$rec['user_id']]))
                    {
                        $seen[$rec['user_id']] = true;
                        $memberCount += 1;
                        if(!empty($rec['visible']) || $bypassUserPrivacy)
                        {
                            $memberVisibleCount += 1;
                            if ($cutoff > 0 && $memberVisibleCount > $cutoff)
                            {
                                continue;
                            }
                            $score = $score - ($score % $sampleInterval);
                            $rec['effective_last_activity'] = $score;
                            $records[] = $rec;
                        }
                    }
                }
                else if (empty($rec['robot']))
                {
                    $guestCount += 1;
                }
                else
                {
                    $robotCount += 1;
                }
            }
        }

        return array
        (
            'members' => $memberCount,
            'guests'  => $guestCount,
            'robots'  => $robotCount,
            'records' => $records,
            'recordsUnseen' => $cutoff > 0 ? $memberVisibleCount - count($records) : 0,
        );
    }

    protected function _getUserModel()
    {
        return $this->getModelFromCache('XenForo_Model_User');
    }
}