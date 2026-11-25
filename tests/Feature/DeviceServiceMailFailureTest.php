<?php

namespace Tests\Feature;

use App\Mail\Api\Ynov\NewDeviceMail;
use App\Models\Api\Ynov\parameter\User;
use App\Services\Api\Ynov\Auth\DeviceService;
use App\Services\Api\Ynov\NotificationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class DeviceServiceMailFailureTest extends TestCase
{
    public function test_new_device_registration_succeeds_when_notification_email_fails(): void
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');

        Schema::connection('sqlite')->create('users', function (Blueprint $table): void {
            $table->string('uuid_user')->primary();
            $table->string('login');
            $table->string('email');
            $table->string('password');
            $table->timestamp('password_expires_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::connection('sqlite')->create('user_details', function (Blueprint $table): void {
            $table->string('uuid_user_details')->primary();
            $table->string('user_uuid');
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::connection('sqlite')->create('user_devices', function (Blueprint $table): void {
            $table->string('uuid_device')->primary();
            $table->string('user_uuid');
            $table->string('fingerprint');
            $table->string('device_name')->nullable();
            $table->string('device_type')->nullable();
            $table->string('os')->nullable();
            $table->string('browser')->nullable();
            $table->string('ip_address');
            $table->text('user_agent');
            $table->string('location')->nullable();
            $table->boolean('is_trusted')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('trusted_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->text('metadata')->nullable();
            $table->timestamps();
        });
        Schema::connection('sqlite')->create('group_notifs', function (Blueprint $table): void {
            $table->string('uuid_group_notif')->primary();
            $table->string('code');
            $table->softDeletes();
            $table->timestamps();
        });

        $user = User::create([
            'uuid_user' => 'user-uuid',
            'login' => 'user@example.com',
            'email' => 'user@example.com',
            'password' => 'Password123!',
        ]);
        $notificationService = Mockery::mock(NotificationService::class);
        $notificationService->shouldReceive('create')->once();

        $mailer = Mockery::mock();
        $mailer->shouldReceive('queue')
            ->once()
            ->with(Mockery::type(NewDeviceMail::class))
            ->andThrow(new \RuntimeException('SMTP unavailable'));
        Mail::shouldReceive('to')->once()->with($user->email)->andReturn($mailer);

        $device = (new DeviceService($notificationService))->updateOrCreate($user, [
            'fingerprint' => 'device-fingerprint',
            'device_name' => 'Test device',
            'device_type' => 'web',
            'os' => 'Windows',
            'browser' => 'Chrome',
            'ip' => '127.0.0.1',
            'user_agent' => 'Test agent',
            'location' => null,
        ]);

        $this->assertNotNull($device->uuid_device);
        $this->assertDatabaseCount('user_devices', 1);
    }
}
