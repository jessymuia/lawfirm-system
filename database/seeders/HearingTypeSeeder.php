<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class HearingTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        //
        // create init hearing types
        $hearingTypes = [
            "Plea Taking",
            "Pretrial Conference",
            "Hearing of Substantive applications",
            "Case Management conference",
            "Trial",
            "Sentence Hearing",
            "Appeal hearing",
            "Interlocutory Application",
            "Judgment delivery",
            "Evidence hearing",
        ];

        foreach($hearingTypes as $hearingType){
            \App\Models\HearingType::create([
                'name' => $hearingType
            ]);
        }
    }
}
