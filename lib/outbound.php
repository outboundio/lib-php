<?php

if (!function_exists('json_encode')) {
    throw new Exception('Outbound requires the JSON extension to work.');
}

class OutboundApiException extends Exception {}
class OutboundDataException extends Exception {}
class OutboundConnectionException extends Exception {}

class Outbound {
    const VERSION = '2.2.0';

    const TRACK = 1;
    const IDENTIFY = 2;
    const REGISTER_APNS = 3;
    const REGISTER_GCM = 4;
    const DISABLE_APNS = 5;
    const DISABLE_GCM = 6;
    const UNSUBSCRIBE_ALL = 7;
    const UNSUBSCRIBE_CAMP = 8;
    const SUBSCRIBE_ALL = 9;
    const SUBSCRIBE_CAMP = 10;

    const APNS = "apns";
    const GCM = "gcm";

    const URL_ROOT = 'https://api.outbound.io/v2';

    private static $api_key;
    private static $connect_timeout;
    private static $timeout;

    public static function init($api_key, $connect_timeout=5, $timeout=30) {
        self::$api_key = $api_key;
        self::$connect_timeout = $connect_timeout;
        self::$timeout = $timeout;
    }

    /**
     * This method exists solely for the purpose of testing and should not be
     * be called outside of the tests.
     */
    public static function reset() {
        self::$api_key = null;
    }

    /**
     * Alias one user id to another.
     *
     * @param string|number user_id - ID of the user who needs to be aliased.
     * @param string|number previous_id - ID of the previous who needs to be aliased.
     * @throws OutboundApiException, OutboundConnectionException, OutboundDataException, Exception
     */
    public static function alias($user_id, $previous_id) {
        self::_ensure_init();
        $user = self::_build_user($user_id);
        $user["previous_id"] = $previous_id;
        self::_execute(self::IDENTIFY, $user);
    }

    /**
     * Make an identify API call to Outbound for a user.
     *
     * @param string|number user_id - ID of the user who needs to be identified.
     * @param Array user_info - Special user attributes such as first name,
     *      email, phone number, etc. Unsupported fields will be removed.
     * @param Array user_attrs [OPTIONAL] - Any extra attributes of the user that
     *      needs to be tracked.
     * @throws OutboundApiException, OutboundConnectionException, OutboundDataException, Exception
     */
    public static function identify($user_id, Array $user_info=null)  {
        self::_ensure_init();
        $user = self::_build_user($user_id, $user_info);
        self::_execute(self::IDENTIFY, $user);
    }

    /**
     * Make a track API call to Outbound for a given event.
     *
     * @param string|number user_id - ID of the user who triggered the event.
     * @param string event - The name of the event that was triggered.
     * @param Array properties [OPTIONAL] - Any event specific properties to be tracked.
     * @throws OutboundApiException, OutboundConnectionException, OutboundDataException, Exception
     */
    public static function track($user_id, $event, Array $properties=null, $timestamp=null) {
        self::_ensure_init();
        $user = self::_build_user($user_id);

        if (!is_string($event)) {
            throw new OutboundDataException('Event must be a string. Received ' . (!is_callable($event) ? gettype($event) : 'function') . '.');
        }

        $data = array(
            'event' => $event,
            'user_id' => $user['user_id'],
        );
        if ($properties) {
            $data['properties'] = $properties;
        }
        if (!$timestamp) {
          $data['timestamp'] = time();
        } else {
          $data['timestamp'] = $timestamp;
        }
        self::_execute(self::TRACK, $data);
    }

    /**
     * Unsubscribe a user from all campaigns on Outbound.
     *
     * @param string|number user_id - Unique ID of the user to unsubscribe
     * @throws OutboundApiException, OutboundConnectionException, OutboundDataException, Exception
     */
    public static function unsubscribe_from_all_campaigns($user_id) {
        self::_make_subscription_call(self::UNSUBSCRIBE_ALL, $user_id, array());
    }

    /**
     * Unsubscribe a user from a specific list of campaigns on Outbound.
     *
     * @param string|number user_id - Unique ID of the user to unsubscribe
     * @param array campaign_ids - List of campaign IDs to unsubscribe the user from.
     * @throws OutboundApiException, OutboundConnectionException, OutboundDataException, Exception
     */
    public static function unsubscribe_from_campaigns($user_id, Array $campaign_ids) {
        self::_make_subscription_call(self::UNSUBSCRIBE_CAMP, $user_id, $campaign_ids);
    }

    /**
     * Subscribe a user too all campaigns on Outbound.
     *
     * @param string|number user_id - Unique ID of the user to unsubscribe
     * @throws OutboundApiException, OutboundConnectionException, OutboundDataException, Exception
     */
    public static function subscribe_to_all_campaigns($user_id) {
        self::_make_subscription_call(self::SUBSCRIBE_ALL, $user_id, array());
    }

    /**
     * Subscribe a user to a specific list of campaigns on Outbound.
     *
     * @param string|number user_id - Unique ID of the user to unsubscribe
     * @param array campaign_ids - List of campaign IDs to unsubscribe the user from.
     * @throws OutboundApiException, OutboundConnectionException, OutboundDataException, Exception
     */
    public static function subscribe_to_campaigns($user_id, Array $campaign_ids) {
        self::_make_subscription_call(self::SUBSCRIBE_CAMP, $user_id, $campaign_ids);
    }

    /**
     * Register a device token for push notifications.
     *
     * @param string platform - The platform the token is for (Outbound::APNS or Outbound::GCM)
     * @param string|number user_id - ID of the user who the token belongs to.
     * @param string token - The token registered to the user's device.
     * @throws OutboundApiException, OutboundConnectionException, OutboundDataException, Exception
     */
    public static function register_token($platform, $user_id, $token) {
        self::_ensure_init();
        self::_validate_user_id($user_id);

        if (!in_array($platform, array(self::APNS, self::GCM))) {
            throw new OutboundDataException('Invalid platform (' . $platform . ').');
        }

        if (!is_string($token)) {
            throw new OutboundDataException('Token must be a string. Received ' . (!is_callable($token) ? gettype($token) : 'function') . '.');
        }

        $data = array(
            'token' => $token,
            'user_id' => $user_id,
        );
        self::_execute($platform == self::APNS ? self::REGISTER_APNS : self::REGISTER_GCM, $data);
    }

    /**
     * Disable a device token for push notifications.
     *
     * @param string platform - The platform the token is for (Outbound::APNS or Outbound::GCM)
     * @param string|number user_id - ID of the user who the token belongs to.
     * @param string token - The token registered to the user's device.
     * @throws OutboundApiException, OutboundConnectionException, OutboundDataException, Exception
     */
    public static function disable_token($platform, $user_id, $token) {
        self::_ensure_init();
        self::_validate_user_id($user_id);

        if (!in_array($platform, array(self::APNS, self::GCM))) {
            throw new OutboundDataException('Invalid platform (' . $platform . ').');
        }

        if (!is_string($token)) {
            throw new OutboundDataException('Token must be a string. Received ' . (!is_callable($token) ? gettype($token) : 'function') . '.');
        }

        $data = array(
            'token' => $token,
            'user_id' => $user_id,
        );
        self::_execute($platform == self::APNS ? self::DISABLE_APNS : self::DISABLE_GCM, $data);
    }

    /**
     * Disable all device tokens a user has on the specified platform.
     *
     * @param string platform - The platform the token is for (Outbound::APNS or Outbound::GCM)
     * @param string|number user_id - ID of the user who the token belongs to.
     * @throws OutboundApiException, OutboundConnectionException, OutboundDataException, Exception
     */
    public static function disable_all_tokens($platform, $user_id) {
        self::_ensure_init();
        self::_validate_user_id($user_id);

        if (!in_array($platform, array(self::APNS, self::GCM))) {
            throw new OutboundDataException('Invalid platform (' . $platform . ').');
        }

        $data = array(
            'all' => true,
            'user_id' => $user_id,
        );
        self::_execute($platform == self::APNS ? self::DISABLE_APNS : self::DISABLE_GCM, $data);
    }

    private static function _ensure_init() {
        if (!self::$api_key) {
            throw new Exception('init() must be called before anything else.');
        }
    }

    /**
     * Validate a user id is of a supported type.
     *
     * @param string|number user_id - User ID to validate. Anything other than
     *      string or number results in exception.
     * @throws OutboundDataException
     * @return bool
     */
    private static function _validate_user_id($user_id) {
        if (is_string($user_id) || is_numeric($user_id)) {
            return true;
        }
        throw new OutboundDataException('User ID must be string or integer. Received ' . (!is_callable($user_id) ? gettype($user_id) : 'function') . '.');
    }

    /**
     * Build a user data array with the given info.
     *
     * @param string|number user_id - User ID to include in data.
     * @param Array user_info - Special user attributes such as first name,
     *      email, phone number, etc. Unsupported fields will be removed.
     * @param Array user_attrs [OPTIONAL] - Any extra attributes of the user that
     *      needs to be tracked.
     * @throws OutboundDataException
     * @return Array
     */
    private static function _build_user($user_id, Array $user_info=null) {
        self::_validate_user_id($user_id);

        $user_info = $user_info ? $user_info : array();
        $user_info = array_intersect_key(
            $user_info,
            array(
                'first_name' => null,
                'last_name' => null,
                'email' => null,
                'phone_number' => null,
                'apns' => null,
                'gcm' => null,
                'group_id' => null,
                'group_attributes' => null,
                'previous_id' => null,
                'attributes' => null,
            )
        );
        $user_info['user_id'] = $user_id;

        return array_filter($user_info, function($v) {
          return !empty($v);
        });
    }

    private static function _make_subscription_call($call, $user_id, Array $campaign_ids) {
        self::_ensure_init();
        self::_validate_user_id($user_id);

        $data = array(
            "user_id" => $user_id,
        );

        if ($call == self::UNSUBSCRIBE_CAMP || $call == self::SUBSCRIBE_CAMP) {
            if (count($campaign_ids) == 0) {
                throw new OutboundDataException("At least one campaign required.");
            }
            $data["campaign_ids"] = $campaign_ids;
        }
        self::_execute($call, $data);
    }

    private static function _build_url($call) {
        $subscribe_calls = array(self::SUBSCRIBE_ALL, self::SUBSCRIBE_CAMP);
        $unsubscribe_calls = array(self::UNSUBSCRIBE_ALL, self::UNSUBSCRIBE_CAMP);
        $all_subscriptions = array(self::SUBSCRIBE_ALL, self::UNSUBSCRIBE_ALL);
        $camp_subscriptions = array(self::SUBSCRIBE_CAMP, self::UNSUBSCRIBE_CAMP);
        $subscription_calls = array_merge($subscribe_calls, $unsubscribe_calls);

        $url = self::URL_ROOT;
        if ($call == self::TRACK) {
            $url .= '/track';
        } elseif ($call == self::IDENTIFY) {
            $url .= '/identify';
        } elseif ($call == self::REGISTER_APNS) {
            $url .= '/apns/register';
        } elseif ($call == self::REGISTER_GCM) {
            $url .= '/gcm/register';
        } elseif ($call == self::DISABLE_APNS) {
            $url .= '/apns/disable';
        } elseif ($call == self::DISABLE_GCM) {
            $url .= '/gcm/disable';
        } elseif (in_array($call, $subscription_calls)) {
            if (in_array($call, $subscribe_calls)) {
                $url .= '/subscribe';
            } else {
                $url .= '/unsubscribe';
            }

            if (in_array($call, $all_subscriptions)) {
                $url .= '/all';
            } else {
                $url .= '/campaigns';
            }
        } else {
            throw new Exception('Unsupported API call (' . $call . ') given.');
        }
        return $url;
    }

    /**
     * Excute API call to Outbound.
     *
     * @param int call - One of the supported API call constants.
     * @param Array data - Data to be sent to Outbound.
     * @throws OutboundConnectionException, ApiError, Exception
     */
    private static function _execute($call, Array $data) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, self::_build_url($call));
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-type: application/json',
            'X-Outbound-Key: ' . self::$api_key,
            'X-Outbound-Client: PHP/' . self::VERSION,
        ));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::$connect_timeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::$timeout);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        $response = curl_exec($ch);

        if (false === $response) {
            $curl_error = curl_error($ch);
            $curl_errno = curl_errno($ch);
            curl_close($ch);

            throw new OutboundConnectionException('Unknown cURL error: ' . $curl_errno . ' - ' . $curl_error);
        } else {
            curl_close($ch);

            $response = trim($response);
            if ($response == '') {
                return true;
            } else {
                $err = json_decode($response, true);
                throw new OutboundApiException($err['error']['Message'], $err['error']['Code']);
            }
        }
    }
}
