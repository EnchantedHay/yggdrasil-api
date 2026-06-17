<?php

namespace Yggdrasil\Middleware;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Yggdrasil\Exceptions\ForbiddenOperationException;

/**
 * 校验 MUA 联盟中央服务器到本站受信回调（api/union/member/*）的签名请求。
 *
 * 中央服务器在每次回调时附带 X-Message-Signature/Timestamp/Nonce 三个头：
 *   - signature = base64( SHA256withRSA(body + timestamp + nonce) )
 *   - 验签公钥从 GET {union_api_root} 的 union_host_signature_public_key 字段拉取
 *   - timestamp 容忍 -10s ~ +30s，nonce 60s 内不可重放
 */
class UnionHostVerify
{
    public function handle($request, Closure $next)
    {
        $signature = $request->header('X-Message-Signature');
        $timestamp = $request->header('X-Message-Timestamp');
        $nonce = $request->header('X-Message-Nonce');
        $body = $request->getContent();

        if (! $signature || ! $timestamp || ! $nonce) {
            Log::channel('ygg')->info('Union host verification failure: Missing signature headers.');
            throw new ForbiddenOperationException('Union host verification failure.');
        }

        // 防重放：同一 nonce 60s 内只接受一次
        if (Cache::has('union_host_signature_'.$nonce)) {
            Log::channel('ygg')->info('Union host verification failure: Invalid nonce.');
            throw new ForbiddenOperationException('Union host verification failure.');
        }

        // 时间戳偏差校验
        if ($timestamp < time() - 10 || $timestamp > time() + 30) {
            Log::channel('ygg')->info('Union host verification failure: Invalid timestamp.');
            throw new ForbiddenOperationException('Union host verification failure.');
        }

        // 拉取联盟公钥（连接失败等同验签失败）
        try {
            $publicKey = Http::timeout(5.0)->get(option('union_api_root'))->json('union_host_signature_public_key');
        } catch (\Exception $e) {
            Log::channel('ygg')->info('Union host verification failure: Cannot fetch public key. '.$e->getMessage());
            throw new ForbiddenOperationException('Union host verification failure.');
        }

        if (! $publicKey) {
            Log::channel('ygg')->info('Union host verification failure: Public key missing in upstream response.');
            throw new ForbiddenOperationException('Union host verification failure.');
        }

        if (openssl_verify($body.$timestamp.$nonce, base64_decode($signature), $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
            Log::channel('ygg')->info('Union host verification failure: Invalid signature.');
            throw new ForbiddenOperationException('Union host verification failure.');
        }

        Cache::put('union_host_signature_'.$nonce, $signature, 60);

        return $next($request);
    }
}
