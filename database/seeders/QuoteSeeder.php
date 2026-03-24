<?php

namespace Database\Seeders;

use App\Models\Quote;
use App\Models\User;
use Illuminate\Database\Seeder;

class QuoteSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $kine = User::find('3fbe6057-dfe0-45d8-8245-bb982326d1dc');
        $patient = User::find('019bb70c-f96e-71ae-9cce-70a144fb5198');

        $quotes = [
            [
                'content' => 'La persévérance est la clé de la guérison. Chaque exercice vous rapproche de votre objectif.',
                'author' => 'Dr. Marie Dubois',
                'author_title' => 'Kinésithérapeute',
                'order_index' => 1,
            ],
            [
                'content' => 'Votre corps peut surmonter presque tout. C\'est votre esprit que vous devez convaincre.',
                'author' => 'Dr. Pierre Martin',
                'author_title' => 'Kinésithérapeute du sport',
                'order_index' => 2,
            ],
            [
                'content' => 'La douleur d\'aujourd\'hui est la force de demain. Continuez, vous y êtes presque.',
                'author' => 'Dr. Sophie Laurent',
                'author_title' => 'Kinésithérapeute',
                'order_index' => 3,
            ],
            [
                'content' => 'Chaque petit progrès est une victoire. Célébrez vos réussites, même les plus petites.',
                'author' => 'Dr. Thomas Bernard',
                'author_title' => 'Kinésithérapeute',
                'order_index' => 4,
            ],
            [
                'content' => 'La rééducation n\'est pas une course, c\'est un marathon. Prenez votre temps, mais ne vous arrêtez pas.',
                'author' => 'Dr. Claire Fontaine',
                'author_title' => 'Kinésithérapeute',
                'order_index' => 5,
            ],
        ];

        foreach ($quotes as $quoteData) {
            $quote = Quote::create($quoteData);

            $patient->assignedQuotes()->attach($quote->id, [
                'kine_id' => $kine->id,
                'is_active' => true,
                'order_index' => 1
            ]);

        }
    }
}
