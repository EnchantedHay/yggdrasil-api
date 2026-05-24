<?php

namespace Yggdrasil\Controllers;

use DB;
use Log;
use Cache;
use Schema;
use App\Models\User;
use App\Models\Player;
use Yggdrasil\Models\Token;
use Illuminate\Http\Request;
use Yggdrasil\Models\Profile;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http;
use Yggdrasil\Exceptions\ForbiddenOperationException;

class SessionController extends Controller
{
    public function joinServer(Request $request)
    {
        $accessToken = $request->input('accessToken');
        $selectedProfile = $request->input('selectedProfile');
        $serverId = $request->input('serverId');

        Log::channel('ygg')->info("Player [$selectedProfile] is trying to join server [$serverId] with access token [$accessToken]");

        $result = DB::table('uuid')->where('uuid', $selectedProfile)->first();

        if (! $result) {
            // 据说 Mojang 在这种情况下是会返回 403 的
            throw new ForbiddenOperationException(
                trans('Yggdrasil::exceptions.uuid', ['profile' => $selectedProfile])
            );
        }

        $player = Player::where('name', $result->name)->first();

        if (! $player) {
            // 删除已失效的 UUID 映射（e.g. 其对应的角色已被删除）
            DB::table('uuid')->where('uuid', $selectedProfile)->delete();

            throw new ForbiddenOperationException(
                trans('Yggdrasil::exceptions.uuid', ['profile' => $selectedProfile])
            );
        }

        $identification = strtolower($player->user->email);

        Log::channel('ygg')->info("Player [$selectedProfile]'s name is [$player->name], belongs to user [$identification]");

        $token = Token::lookup($accessToken);
        if ($token && $token->isValid()) {

            Log::channel('ygg')->info("All access tokens issued for user [$identification] are as listed", [$token]);

            if ($token->accessToken != $accessToken) {
                throw new ForbiddenOperationException(trans('Yggdrasil::exceptions.token.invalid'));
            }

            if ($token->profileId != $selectedProfile) {
                throw new ForbiddenOperationException(trans('Yggdrasil::exceptions.player.not-matched'));
            }

            if ($player->user->permission == User::BANNED) {
                throw new ForbiddenOperationException(trans('Yggdrasil::exceptions.user.banned'));
            }

            // 加入服务器，缓存 120 秒（与 hasJoinedServer 一侧对应）
            Cache::put("SERVER_$serverId", ['profile' => $selectedProfile, 'ip' => $request->ip()], 120);
        } else {
            // 指定角色所属的用户没有签发任何令牌
            throw new ForbiddenOperationException(trans('Yggdrasil::exceptions.token.missing'));
        }

        Log::channel('ygg')->info("Player [$selectedProfile] successfully joined the server [$serverId]");

        ygg_log([
            'action' => 'join',
            'user_id' => $player->uid,
            'player_id' => $player->pid,
            'parameters' => json_encode($request->except('accessToken')),
        ]);

        return response('')->setStatusCode(204);
    }

    public function hasJoinedServer(Request $request)
    {
        $name = $request->input('username');
        $serverId = $request->input('serverId');
        $ip = $request->input('ip');

        Log::channel('ygg')->info("Checking if player [$name] has joined the server [$serverId] with IP [$ip]");

        // 检查是否进行过外置登录的 join 请求
        if ($session = Cache::get("SERVER_$serverId")) {
            $cachedProfile = is_array($session) ? ($session['profile'] ?? null) : $session;
            $cachedIp     = is_array($session) ? ($session['ip'] ?? null) : null;

            $profile = $cachedProfile ? Profile::createFromUuid($cachedProfile) : null;

            if ($profile && $name === $profile->name) {
                // IP 校验：双方都有 IP 时才比对，任一方没有则跳过
                if ($ip && $cachedIp && $ip !== $cachedIp) {
                    Log::channel('ygg')->warning("Player [$name] IP mismatch: expected [$cachedIp], got [$ip]");
                    return response('')->setStatusCode(204);
                }

                Cache::forget("SERVER_$serverId");
                Log::channel('ygg')->info("Player [$name] was in the server [$serverId]");

                $response = $profile->serialize(false);
                Log::channel('ygg')->info("Returning player [$name]'s profile", [$response]);

                ygg_log(array_merge([
                    'action' => 'has_joined',
                    'user_id' => $profile->player->uid,
                    'player_id' => $profile->player->pid,
                    'parameters' => json_encode($request->except('username')),
                ], ($ip ? compact('ip') : [])));

                return response()->json()->setContent($response);
            }
        }

        // 外置缓存未命中，尝试向 Mojang 转发验证（正版账号回落）
        if (Schema::hasTable('mojang_verifications')) {
            $profile = $this->hasJoinedMojang($name, $serverId);
            if ($profile) {
                Log::channel('ygg')->info("Player [$name] verified via Mojang, returning bound profile [{$profile->name}]");

                $response = $profile->serialize(false);

                ygg_log(array_merge([
                    'action' => 'has_joined',
                    'user_id' => $profile->player->uid,
                    'player_id' => $profile->player->pid,
                    'parameters' => json_encode($request->except('username')),
                ], ($ip ? compact('ip') : [])));

                return response()->json()->setContent($response);
            }
        }

        Log::channel('ygg')->info("Player [$name] was not in the server [$serverId]");
        return response('')->setStatusCode(204);
    }

    protected function hasJoinedMojang(string $name, string $serverId): ?Profile
    {
        try {
            $response = Http::get('https://sessionserver.mojang.com/session/minecraft/hasJoined', [
                'username' => $name,
                'serverId' => $serverId,
            ]);

            if ($response->status() !== 200) {
                return null;
            }

            $mojangUuid = str_replace('-', '', $response->json('id') ?? '');
            if (! $mojangUuid) {
                return null;
            }

            Log::channel('ygg')->info("Mojang verified player [$name] with uuid [$mojangUuid]");

            $binding = DB::table('mojang_verifications')
                ->where('mojang_uuid', $mojangUuid)
                ->first();

            if (! $binding) {
                // 检查是否有等待中的绑定申请，有则自动完成绑定
                if (Schema::hasTable('pending_mojang_bind')) {
                    $pending = DB::table('pending_mojang_bind')
                        ->where('mojang_name', strtolower($name))
                        ->where('created_at', '>=', now()->subMinutes(15))
                        ->first();

                    if ($pending) {
                        $pendingUser = User::find($pending->user_id);
                        $pendingPlayer = $pendingUser && $pendingUser->permission != User::BANNED
                            ? Player::where('uid', $pending->user_id)->first()
                            : null;

                        if ($pendingPlayer) {
                            DB::table('mojang_verifications')->updateOrInsert(
                                ['user_id' => $pending->user_id],
                                ['mojang_uuid' => $mojangUuid]
                            );
                            DB::table('pending_mojang_bind')
                                ->where('user_id', $pending->user_id)
                                ->delete();

                            Log::channel('ygg')->info("Auto-bound Mojang [$mojangUuid / $name] to bs user [{$pending->user_id}]");
                            return Profile::createFromPlayer($pendingPlayer);
                        }
                    }
                }

                Log::channel('ygg')->info("Mojang uuid [$mojangUuid] has no binding, rejecting.");
                return null;
            }

            $user = User::find($binding->user_id);
            if (! $user || $user->permission == User::BANNED) {
                return null;
            }

            $player = Player::where('uid', $binding->user_id)->first();
            if (! $player) {
                return null;
            }

            return Profile::createFromPlayer($player);
        } catch (\Exception $e) {
            Log::channel('ygg')->warning("Mojang hasJoined forwarding failed: " . $e->getMessage());
            return null;
        }
    }

}
