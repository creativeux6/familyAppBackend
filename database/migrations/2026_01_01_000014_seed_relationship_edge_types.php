<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('relationship_edge_types')->insert([
            [
                'code' => 'parent_of',
                'inverse_code' => 'child_of',
                'is_symmetric' => false,
                'label' => 'Parent',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'spouse_of',
                'inverse_code' => 'spouse_of',
                'is_symmetric' => true,
                'label' => 'Spouse',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'adoptive_parent_of',
                'inverse_code' => 'adoptive_child_of',
                'is_symmetric' => false,
                'label' => 'Adoptive Parent',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'step_parent_of',
                'inverse_code' => 'step_child_of',
                'is_symmetric' => false,
                'label' => 'Step Parent',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        DB::table('relationship_edge_types')->whereIn('code', [
            'parent_of', 'spouse_of', 'adoptive_parent_of', 'step_parent_of',
        ])->delete();
    }
};
