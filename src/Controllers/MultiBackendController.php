<?php

namespace Yggdrasil\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Yggdrasil\Exceptions\IllegalArgumentException;

/**
 * 联盟 multi-backend 重签端点：接收任意 profile，对其每条 property 的 value
 * 用本站（即联盟共享）私钥重新签名后返回。
 *
 * 这是 MUA 联盟用来在跨站交换 profile 时统一签名版本的兜底机制。
 */
class MultiBackendController extends Controller
{
    public function hello()
    {
        return ['status' => 'success'];
    }

    public function restore(Request $request)
    {
        if (! filter_var(option('ygg_restore_api'), FILTER_VALIDATE_BOOLEAN)) {
            abort(403, trans('Yggdrasil::exceptions.restore.api_disabled'));
        }

        $key = openssl_pkey_get_private(option('ygg_private_key'));

        if (! $key) {
            throw new IllegalArgumentException(trans('Yggdrasil::config.rsa.invalid'));
        }

        $profile = $request->input();

        foreach ($profile['properties'] as &$prop) {
            openssl_sign($prop['value'], $signature, $key);
            $prop['signature'] = base64_encode($signature);
        }
        unset($prop);

        return $profile;
    }
}
