<?php

namespace Database\Seeders;

use App\Models\Season;
use Illuminate\Database\Seeder;

class TeamSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $activeSeason = Season::query()
            ->where('current', true)
            ->sole();

        $activeSeason
            ->teams()
            ->createMany([
                ['main_name' => 'Atlético de Madrid', 'name' => 'Club Atlético de Madrid SAD', 'slug' => 'atletico-de-madrid', 'short_name' => 'ATM', 'logo' => '', 'fantasy_id' => 2],
                ['main_name' => 'Athletic Club', 'name' => 'Athletic Club', 'slug' => 'athletic-club', 'short_name' => 'ATH', 'logo' => '', 'fantasy_id' => 3],
                ['main_name' => 'FC Barcelona', 'name' => 'Fútbol Club Barcelona', 'slug' => 'fc-barcelona', 'short_name' => 'BAR', 'logo' => '', 'fantasy_id' => 4],
                ['main_name' => 'Real Betis', 'name' => 'Real Betis Balompié SAD', 'slug' => 'real-betis', 'short_name' => 'BET', 'logo' => '', 'fantasy_id' => 5],
                ['main_name' => 'Celta', 'name' => 'Real Club Celta de Vigo SAD', 'slug' => 'rc-celta', 'short_name' => 'CEL', 'logo' => '', 'fantasy_id' => 6],
                ['main_name' => 'Elche CF', 'name' => 'Elche Club de Fútbol SAD', 'slug' => 'elche-c-f', 'short_name' => 'ELC', 'logo' => '', 'fantasy_id' => 7],
                ['main_name' => 'RCD Espanyol', 'name' => 'RCD Espanyol', 'slug' => 'rcd-espanyol', 'short_name' => 'ESP', 'logo' => '', 'fantasy_id' => 8],
                ['main_name' => 'Getafe CF', 'name' => 'Getafe Club de Fútbol SAD', 'slug' => 'getafe-cf', 'short_name' => 'GET', 'logo' => '', 'fantasy_id' => 9],
                ['main_name' => 'Levante UD', 'name' => 'Levante Unión Deportiva SAD', 'slug' => 'levante-ud', 'short_name' => 'LEV', 'logo' => '', 'fantasy_id' => 11],
                ['main_name' => 'Málaga CF', 'name' => 'Málaga Club de Fútbol SAD', 'slug' => 'malaga-cf', 'short_name' => 'MGA', 'logo' => '', 'fantasy_id' => 12],
                ['main_name' => 'C.A. Osasuna', 'name' => 'Club Atlético Osasuna', 'slug' => 'c-a-osasuna', 'short_name' => 'OSA', 'logo' => '', 'fantasy_id' => 13],
                ['main_name' => 'Rayo Vallecano', 'name' => 'Rayo Vallecano de Madrid SAD', 'slug' => 'rayo-vallecano', 'short_name' => 'RAY', 'logo' => '', 'fantasy_id' => 14],
                ['main_name' => 'Real Madrid', 'name' => 'Real Madrid Club de Fútbol', 'slug' => 'real-madrid', 'short_name' => 'RMA', 'logo' => '', 'fantasy_id' => 15],
                ['main_name' => 'Real Sociedad', 'name' => 'Real Sociedad de Fútbol SAD', 'slug' => 'real-sociedad', 'short_name' => 'RSO', 'logo' => '', 'fantasy_id' => 16],
                ['main_name' => 'Sevilla FC', 'name' => 'Sevilla Fútbol Club SAD', 'slug' => 'sevilla-fc', 'short_name' => 'SEV', 'logo' => '', 'fantasy_id' => 17],
                ['main_name' => 'Valencia CF', 'name' => 'Valencia Club de Fútbol SAD', 'slug' => 'valencia-cf', 'short_name' => 'VAL', 'logo' => '', 'fantasy_id' => 18],
                ['main_name' => 'Villarreal CF', 'name' => 'Villarreal Club de Fútbol SAD', 'slug' => 'villarreal-cf', 'short_name' => 'VIL', 'logo' => '', 'fantasy_id' => 20],
                ['main_name' => 'Deportivo Alavés', 'name' => 'Deportivo Alavés SAD', 'slug' => 'd-alaves', 'short_name' => 'ALA', 'logo' => '', 'fantasy_id' => 21],
                ['main_name' => 'RC Deportivo', 'name' => 'Real Club Deportivo de La Coruña SAD', 'slug' => 'rc-deportivo', 'short_name' => 'RCD', 'logo' => '', 'fantasy_id' => 26],
                ['main_name' => 'R. Racing Club', 'name' => 'Real Racing Club SAD', 'slug' => 'r-racing-club', 'short_name' => 'RAC', 'logo' => '', 'fantasy_id' => 49],
            ]);
    }
}
