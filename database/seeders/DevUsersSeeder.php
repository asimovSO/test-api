<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DevUsersSeeder extends Seeder
{
    /**
     * Пользователи с постоянными токенами для локальной разработки.
     *
     * Sanctum хранит в БД sha256-хеш секрета, а клиенту отдаёт строку
     * "{id токена}|{секрет}". Раз хеш считаем сами — токен можно задать
     * заранее и не перелогиниваться в Bruno после каждого migrate:fresh.
     */
    private const USERS = [
        [
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'token_id' => 1,
            'secret' => 'dev-token-alice',
        ],
        [
            'name' => 'Bob',
            'email' => 'bob@example.com',
            'token_id' => 2,
            'secret' => 'dev-token-bob',
        ],
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command->error('DevUsersSeeder не должен запускаться на production.');

            return;
        }

        foreach (self::USERS as $data) {
            $user = User::updateOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'password' => 'password',
                ]
            );

            // Пишем напрямую через query builder: у PersonalAccessToken
            // tokenable_type/tokenable_id и id не входят в $fillable,
            // поэтому массовое присваивание их бы молча отбросило.
            DB::table('personal_access_tokens')->updateOrInsert(
                ['id' => $data['token_id']],
                [
                    'tokenable_type' => User::class,
                    'tokenable_id' => $user->id,
                    'name' => 'dev',
                    'token' => hash('sha256', $data['secret']),
                    'abilities' => json_encode(['*']),
                    'expires_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            $this->command->info(sprintf(
                '%-20s Bearer %d|%s',
                $data['email'],
                $data['token_id'],
                $data['secret']
            ));
        }
    }
}
