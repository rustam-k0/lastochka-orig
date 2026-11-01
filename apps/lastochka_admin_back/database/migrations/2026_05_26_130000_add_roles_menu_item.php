<?php

use App\Models\MenuItem;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        MenuItem::updateOrCreate(
            ['name' => 'roles'],
            [
                'title'      => 'Роли и доступы',
                'icon'       => 'lock-line',
                'position'   => 16,
                'is_visible' => true,
                'accesses'   => ['admin'],
            ]
        );
    }

    public function down(): void
    {
        MenuItem::where('name', 'roles')->delete();
    }
};
