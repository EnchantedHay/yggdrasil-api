<?php

namespace Yggdrasil\Controllers;

use DB;
use Schema;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

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

        return view('Yggdrasil::bind', [
            'binding' => $binding,
            'pending' => $pending,
            'success' => session('success'),
            'error'   => session('error'),
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

        if (Schema::hasTable('mojang_verifications') &&
            DB::table('mojang_verifications')->where('user_id', $uid)->exists()) {
            return redirect(url('yggdrasil/mojang/bind'))
                ->with('error', '您已绑定正版账号，如需更换请先解除绑定。');
        }

        // 清理所有已过期的申请记录
        DB::table('pending_mojang_bind')
            ->where('created_at', '<', now()->subMinutes(15))
            ->delete();

        DB::table('pending_mojang_bind')->updateOrInsert(
            ['user_id' => $uid],
            ['mojang_name' => strtolower($mojangName), 'created_at' => now()]
        );

        return redirect(url('yggdrasil/mojang/bind'))
            ->with('success', '申请已提交！请在 15 分钟内用正版启动器加入服务器，检测到后将自动完成绑定。');
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
