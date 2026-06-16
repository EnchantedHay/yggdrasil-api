<?php

namespace Yggdrasil\Controllers;

use DB;
use Schema;
use App\Models\Player;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http;

class MojangBindController extends Controller
{
    public function index()
    {
        $uid = auth()->user()->uid;

        $binding = Schema::hasTable('mojang_verifications')
            ? DB::table('mojang_verifications')->where('user_id', $uid)->first()
            : null;

        $pending = Schema::hasTable('pending_mojang_bind')
            ? DB::table('pending_mojang_bind')
                ->where('user_id', $uid)
                ->where('created_at', '>=', now()->subMinutes(15))
                ->first()
            : null;

        $hasPlayer = Player::where('uid', $uid)->exists();

        return view('Yggdrasil::bind', [
            'binding'   => $binding,
            'pending'   => $pending,
            'hasPlayer' => $hasPlayer,
            'success'   => session('success'),
            'error'     => session('error'),
        ]);
    }

    public function requestBind(Request $request)
    {
        $mojangName = trim($request->input('mojang_name', ''));

        if (! preg_match('/^[a-zA-Z0-9_]{1,16}$/', $mojangName)) {
            return redirect(url('yggdrasil/mojang/bind'))
                ->with('error', '请输入有效的 Minecraft 用户名（1-16位字母、数字或下划线）。');
        }

        $uid = auth()->user()->uid;

        if (! Player::where('uid', $uid)->exists()) {
            return redirect(url('yggdrasil/mojang/bind'))
                ->with('error', '请先在角色管理页面创建一个角色，再进行正版账号绑定。');
        }

        if (Schema::hasTable('mojang_verifications') &&
            DB::table('mojang_verifications')->where('user_id', $uid)->exists()) {
            return redirect(url('yggdrasil/mojang/bind'))
                ->with('error', '您已绑定正版账号，如需更换请先解除绑定。');
        }

        // 向 Mojang 查询规范用户名与 UUID：既校验账号是否真实存在，也消除大小写歧义
        $resolved = $this->resolveMojangProfile($mojangName);

        if (! $resolved) {
            return redirect(url('yggdrasil/mojang/bind'))
                ->with('error', "未能在 Mojang 找到正版账号「{$mojangName}」，请检查拼写后重试。");
        }

        // 该正版账号是否已被其他用户绑定
        if (Schema::hasTable('mojang_verifications') &&
            DB::table('mojang_verifications')
                ->where('mojang_uuid', $resolved['uuid'])
                ->where('user_id', '!=', $uid)
                ->exists()) {
            return redirect(url('yggdrasil/mojang/bind'))
                ->with('error', '该正版账号已被其他用户绑定。');
        }

        // 清理所有已过期的申请记录
        DB::table('pending_mojang_bind')
            ->where('created_at', '<', now()->subMinutes(15))
            ->delete();

        $values = ['mojang_name' => $resolved['name'], 'created_at' => now()];
        if (Schema::hasColumn('pending_mojang_bind', 'mojang_uuid')) {
            $values['mojang_uuid'] = $resolved['uuid'];
        }

        DB::table('pending_mojang_bind')->updateOrInsert(['user_id' => $uid], $values);

        return redirect(url('yggdrasil/mojang/bind'))
            ->with('success', "已确认正版账号「{$resolved['name']}」。请在 15 分钟内用该正版账号加入服务器，检测到后将自动完成绑定。");
    }

    /**
     * 向 Mojang 查询正版账号的规范用户名与 UUID。
     * 找不到（账号不存在）或网络异常时返回 null。
     */
    protected function resolveMojangProfile(string $name): ?array
    {
        try {
            $response = Http::get('https://api.mojang.com/users/profiles/minecraft/'.$name);

            if ($response->status() !== 200) {
                return null;
            }

            $uuid = str_replace('-', '', $response->json('id') ?? '');
            $canonical = $response->json('name');

            if (! $uuid || ! $canonical) {
                return null;
            }

            return ['uuid' => $uuid, 'name' => $canonical];
        } catch (\Exception $e) {
            return null;
        }
    }

    public function cancelBind()
    {
        DB::table('pending_mojang_bind')
            ->where('user_id', auth()->user()->uid)
            ->delete();

        return redirect(url('yggdrasil/mojang/bind'))
            ->with('success', '已取消绑定申请。');
    }

    public function unbind()
    {
        DB::table('mojang_verifications')
            ->where('user_id', auth()->user()->uid)
            ->delete();

        return redirect(url('yggdrasil/mojang/bind'))
            ->with('success', '已解除正版账号绑定。');
    }
}
