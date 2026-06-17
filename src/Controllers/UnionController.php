<?php

namespace Yggdrasil\Controllers;

use DB;
use Log;
use App\Models\Player;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http;

/**
 * MUA 联合认证客户端 — 处理本站对中央服务器的拉取以及中央服务器到本站的回调。
 *
 * 受信回调（updateList / updatePrivateKey / serverUpdatesBackendKey / triggerSync /
 * remapUUID / diagnose）由 UnionHostVerify 中间件验签。
 */
class UnionController extends Controller
{
    public function hello()
    {
        return json([
            'yggdrasilApiVersion' => plugin('yggdrasil-api')->version,
            'serverListVersion' => option('union_server_list_version'),
            'privateKeyVersion' => option('union_private_key_version'),
            // 本 fork 暂不实现 unionBlacklist / unionOAuth2 等扩展能力
            'enabledFeatures' => [],
        ])->header('Access-Control-Allow-Origin', '*');
    }

    /**
     * 中央服务器推送：拉取最新的联盟成员站点列表。
     */
    public function updateList()
    {
        try {
            $response = Http::timeout(5.0)
                ->withHeaders(['X-Union-Member-Key' => option('union_member_key')])
                ->get(option('union_api_root').'/serverlist');
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }

        if ($response->failed()) {
            return ['status' => 'error', 'http' => $response->status(), 'body' => $response->body()];
        }

        option(['union_server_list' => json_encode($response['servers'])]);
        option(['union_server_list_version' => $response['version']]);

        Log::channel('ygg')->info('Updated union server list.', ['servers' => $response['servers']]);

        return [
            'status' => 'ok',
            'version' => $response['version'],
            'servers' => count($response['servers'] ?? []),
        ];
    }

    /**
     * 中央服务器推送：拉取最新的联盟共享私钥。
     *
     * 注意：成功后本站的 ygg_private_key 会被联盟统一密钥覆盖。
     */
    public function updatePrivateKey()
    {
        try {
            $response = Http::timeout(5.0)
                ->withHeaders(['X-Union-Member-Key' => option('union_member_key')])
                ->get(option('union_api_root').'/privatekey');
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }

        if ($response->failed()) {
            return ['status' => 'error', 'http' => $response->status(), 'body' => $response->body()];
        }

        option(['ygg_private_key' => $response['privateKey']]);
        option(['union_private_key_version' => $response['privateKeyVersion']]);

        Log::channel('ygg')->info('Updated union private key.');

        return [
            'status' => 'ok',
            'privateKeyVersion' => $response['privateKeyVersion'],
        ];
    }

    /**
     * 中央服务器推送：换发新的 member_key。
     */
    public function serverUpdatesBackendKey(Request $request)
    {
        option(['union_member_key' => $request->input('key')]);

        Log::channel('ygg')->info('Union member key rotated by upstream.');
    }

    /**
     * 把本站 `players` 与 `uuid` 表的 (name → uuid) 映射同步到中央服务器。
     *
     * 中央接收端只关心 name → uuid，pid 字段对它无意义；
     * 因此 fork 仓库的 `(id, name, uuid)` schema 也能正常工作。
     */
    public function triggerSync()
    {
        $names = Player::all()->pluck('name');
        $uuids = DB::table('uuid')->pluck('uuid', 'name');

        // 只同步既存在角色、又有 UUID 映射的条目
        $profiles = $uuids->only($names->all())->flip();

        try {
            $response = Http::timeout(15.0)
                ->withHeaders(['X-Union-Member-Key' => option('union_member_key')])
                ->post(option('union_api_root').'/sync', ['profileList' => $profiles]);
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }

        if ($response->failed()) {
            return ['status' => 'error', 'http' => $response->status(), 'body' => $response->body()];
        }

        Log::channel('ygg')->info('Triggered union sync.', ['count' => $profiles->count()]);

        return [
            'status' => 'ok',
            'pushed' => $profiles->count(),
        ];
    }

    /**
     * 中央服务器推送：把本地 uuid 表里冲突的 UUID 改写为联盟仲裁后的新 UUID。
     */
    public function remapUUID(Request $request)
    {
        $remapped = $request->input('remapped_uuid', []);

        foreach ($remapped as $uuid => $mappedUuid) {
            DB::table('uuid')->where('uuid', $uuid)->update(['uuid' => $mappedUuid]);
        }

        Log::channel('ygg')->info('Remapped union UUIDs.', ['count' => count($remapped)]);
    }

    /**
     * 中央服务器推送：回显 nonce/timestamp，用于诊断双向连通性与时钟偏差。
     */
    public function diagnose(Request $request)
    {
        return [
            'nonce' => $request->input('nonce'),
            'timestamp' => microtime(true),
        ];
    }

    /**
     * 后台管理员手动触发：让中央服务器反向 ping 本站，回收联通性诊断结果。
     */
    public function triggerDiagnose()
    {
        try {
            $response = Http::timeout(10.0)
                ->withHeaders(['X-Union-Member-Key' => option('union_member_key')])
                ->post(option('union_api_root').'/diagnose');

            if ($response->ok()) {
                return ['status' => 'ok', 'data' => $response->json()];
            }

            return ['status' => 'error', 'data' => [
                'status_code' => $response->status(),
                'headers' => $response->headers(),
                'body' => $response->body(),
            ]];
        } catch (\Exception $e) {
            return ['status' => 'error', 'data' => ['exception' => $e->getMessage()]];
        }
    }
}
