<?php
/* For licensing terms, see /license.txt */

class NotificationEvent extends Model
{
    public $table;
    public $columns = [
        'id',
        'title',
        'content',
        'link',
        'persistent',
        'day_diff',
        'event_type',
    ];

    const ACCOUNT_EXPIRATION = 1;
    const JUSTIFICATION_EXPIRATION = 2;
    public $extraFieldName;

    /**
     * Constructor.
     */
    public function __construct()
    {
        parent::__construct();
        $this->table = 'notification_event';
        $this->extraFieldName = 'notification_event';
    }

    public function eventTypeToString($eventTypeId)
    {
        $list = $this->getEventsForSelect();

        return $list[$eventTypeId];
    }

    public function getEventsForSelect()
    {
        return [
            self::ACCOUNT_EXPIRATION => get_lang('AccountExpiration'),
            self::JUSTIFICATION_EXPIRATION => get_lang('JustificationExpiration'),
        ];
    }

    public function getForm(FormValidator $form)
    {
        $form->addText('title', get_lang('Title'));
        $form->addTextarea('content', get_lang('Content'));
        $form->addText('link', get_lang('Link'), false);
        $form->addCheckBox('persistent', get_lang('Persistent'));
        $form->addNumeric('day_diff', get_lang('DayDiff'), false);
        $options = $this->getEventsForSelect();
        $form->addSelect('event_type', get_lang('EventType'), $options);

        return $form;
    }

    public function getUserExtraData($userId)
    {
        $data = UserManager::get_extra_user_data_by_field($userId, $this->extraFieldName);

        return $data['notification_event'];
    }

    public function getNotificationsByUser($userId)
    {
        $userInfo = api_get_user_info($userId);
        $events = $this->get_all();
        $extraFieldData = $this->getUserExtraData(api_get_user_id());
        $allowJustification = api_get_plugin_setting('justification', 'tool_enable') === 'true';

        $userJustificationList = [];
        if ($allowJustification) {
            $plugin = Justification::create();
            $userJustificationList = $plugin->getUserJustificationList($userId);
        }

        $notifications = [];
        foreach ($events as $event) {
            $days = (int) $event['day_diff'];
            $checkIsRead = $event['persistent'] == 0 ? true : false;

            switch ($event['event_type']) {
                case self::ACCOUNT_EXPIRATION:
                    if (empty($userInfo['expiration_date'])) {
                        break;
                    }

                    $id = 'id_'.self::ACCOUNT_EXPIRATION.'_event_'.$event['id'].'_'.$userInfo['id'];

                    $read = false;
                    if ($checkIsRead) {
                        $read = $this->isRead($id, $extraFieldData);
                    }

                    $showNotification = $this->showNotification($userInfo['expiration_date'], $days);
                    if ($showNotification && $read === false) {
                        $notifications[] = [
                            'id' => $id,
                            'title' => $event['title'],
                            'content' => $event['content'],
                            'link' => $event['link'],
                            'persistent' => $event['persistent']
                        ];
                    }
                    break;
                case self::JUSTIFICATION_EXPIRATION:
                    if (!empty($userJustificationList)) {
                        foreach ($userJustificationList as $userJustification) {
                            if (empty($userJustification['date_validity'])) {
                                continue;
                            }
                            $showNotification = $this->showNotification($userJustification['date_validity'], $days);

                            $id = 'id_'.self::JUSTIFICATION_EXPIRATION.'_event_'.$event['id'].'_'.$userJustification['id'];

                            $read = false;
                            if ($checkIsRead) {
                                $read = $this->isRead($id, $extraFieldData);
                            }

                            if ($showNotification && $read === false) {
                                $notifications[] = [
                                    'id' => $id,
                                    'title' => $event['title'],
                                    'content' => $event['content'],
                                    'link' => $event['link'],
                                    'persistent' => $event['persistent']
                                ];
                            }
                        }
                    }
                    break;
            }
        }

        return $notifications;
    }

    public function isRead($id, $extraData)
    {
        $userId = api_get_user_id();

        if (empty($extraData)) {
            return false;
        }

        $data = $this->getUserExtraData($userId);
        if (empty($data)) {
            return false;
        }

        $data = json_decode($data);

        if (in_array($id, $data)) {
            return true;
        }

        return false;
    }

    public function markAsRead($id)
    {
        if (empty($id)) {
            return false;
        }
        $userId = api_get_user_id();
        $data = $this->getUserExtraData($userId);
        if (!empty($data)) {
            $data = json_decode($data);
        } else {
            $data = [];
        }
        $data[] = $id;
        $data = json_encode($data);

        UserManager::update_extra_field_value($userId, $this->extraFieldName, $data);

        return true;
    }

    public function showNotification($date, $dayDiff)
    {
        $today = api_get_utc_datetime();
        $expiration = api_get_utc_datetime($date, false, true);
        $interval = new DateInterval('P'.$dayDiff.'D');
        $diff = $expiration->sub($interval);

        if ($diff->format('Y-m-d H:i:s') < $today) {
            return true;
        }

        return false;
    }

    public function install()
    {
        $sql = "CREATE TABLE IF NOT EXISTS notification_event (
            id INT unsigned NOT NULL auto_increment PRIMARY KEY,
            title VARCHAR(255),
            content TEXT,
            link TEXT,
            persistent INT,
            day_diff INT,
            event_type VARCHAR(255)
        )";
        Database::query($sql);
    }
}
