<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PlayerPosition;
use App\Models\FixtureEvent;
use App\Models\FixtureLineup;
use App\Models\Player;
use App\Models\Season;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

#[Signature('season:link-match-data-players')]
#[Description('Link current season players to their worldcup26.ir athlete id via a hardcoded fantasy_id map, and backfill any fixture_lineups/fixture_events already waiting on that id — run manually, then use season:list-unlinked-match-data-players to find new entries for the map')]
class LinkMatchDataPlayers extends Command
{
    /**
     * fantasy_id => worldcup26.ir athlete id — filled in manually as players are
     * looked up. Run season:list-unlinked-match-data-players to see who's missing.
     * Each entry documents the player and team it was looked up for, e.g.:
     *   12345 => 67890, // Affengruber - ELC
     *
     * @var array<int, int>
     */
    private const array PLAYER_MAP = [
        2776 => 277876, // A. Abqar - GET
        2976 => 308006, // A. Alti - RCD
        2519 => 195032, // A. Batalla - RAY
        3235 => 3129002, // A. Bonel - OSA
        2680 => 332246, // A. Castrín - SEV
        2975 => 96060, // A. Ferllo - RCD
        2565 => 307419, // A. Fortuño - ESP
        3040 => 240624, // A. Herrero - MGA
        2715 => 330433, // A. Iturbe - ELC
        2721 => 381628, // A. Osambela - OSA
        2577 => 345991, // A. Rebbach - ALA
        3160 => 150374, // A. Rodriguez - ALA
        3015 => 414075, // A. Vallecillo - RAC
        3225 => 399200, // Abdelkarim - BAR
        2005 => 300284, // Abel Bretones - OSA
        2545 => 379707, // Adama - ATH
        3209 => 274913, // Adeyemi - BAR
        3067 => 313230, // Affengruber - ELC
        1219 => 323970, // Agirrezabala - RAC
        2453 => 282289, // Agoumé - SEV
        2900 => 410486, // Aguirre - RSO
        2438 => 283891, // Aihen - RSO
        2338 => 288760, // Aimar - OSA
        204 => 144759, // Aitor Fdez - OSA
        3168 => 3129045, // Alberto Calatrava - LEV
        2798 => 313024, // Alemâo - RAY
        3222 => 3117407, // Alexis Ciria - RMA
        2564 => 233802, // Aleñá - ALA
        2754 => 271635, // Alfon - SEV
        3190 => 376156, // Ali Houary - ELC
        1529 => 286604, // Almeida - VAL
        3180 => 3128651, // Alonso - RAY
        3119 => 356639, // Amatucci - RCD
        3135 => 356080, // Andrés Garcia - GET
        3073 => 3141, // Andrés Martín - RAC
        3142 => 214319, // Angeliño - RCD
        2954 => 414292, // Antañón - CEL
        2770 => 281558, // Antony - BET
        2443 => 318063, // Aramburu - RSO
        3007 => 334268, // Arana - RAC
        1674 => 310452, // Arda Güler - RMA
        2335 => 300876, // Areso - ATH
        2846 => 380918, // Arguibide - OSA
        3236 => 308728, // Arnau - VAL
        3194 => 328289, // Arnau Ortiz - ATM
        3148 => 384414, // Asp Jensen - RCD
        3108 => 122260, // Aubameyang - RCD
        2504 => 192133, // Ayoze - VIL
        3071 => 214995, // B. Iglesias - CEL
        2488 => 218793, // B. Mayoral - GET
        2949 => 371608, // B. Sangare - ELC
        1121 => 158151, // Balliu - RAY
        3164 => 334794, // Bardeli - LEV
        701 => 283941, // Barrenetxea - RSO
        1565 => 336634, // Barrios - ATM
        121 => 145078, // Bartra - BET
        3189 => 389448, // Barzic - ELC
        3169 => 383900, // Bauza - ESP
        3196 => 274272, // Bayindir - CEL
        2834 => 396607, // Beitia - RSO
        2414 => 178978, // Bellerín - BET
        3101 => 291281, // Bellingham - RMA
        927 => 209579, // Berenguer - ATH
        3116 => 199833, // Bernardo Silva - RMA
        2973 => 414299, // Bil Nsongo - RCD
        3069 => 265997, // Blanco - ALA
        2926 => 359826, // Boselli - GET
        1890 => 307905, // Boyomo - OSA
        2431 => 246532, // Brahim - RMA
        3224 => 403575, // Brian - BAR
        3149 => 3102992, // Bright Ede - RCD
        2556 => 284769, // Buchanan - VIL
        3084 => 207288, // Budimir - OSA
        3151 => 318284, // Buonanotte - ELC
        2913 => 414293, // Burcio - CEL
        2616 => 3731, // C. Alvarez - LEV
        3037 => 331785, // C. Puga - MGA
        2540 => 76884, // C. Rivero - VAL
        2614 => 271904, // C. Romero - VIL
        2772 => 235072, // C. Soler - RSO
        3111 => 381071, // Cabello - LEV
        3105 => 355902, // Cala - ESP
        1300 => 285150, // Camavinga - RMA
        2387 => 276652, // Camello - RAY
        3125 => 125383, // Canales - RAC
        2906 => 176399, // Cancelo - BAR
        1614 => 289072, // Cardona - VIL
        3129 => 380286, // Carlos Espí - RMA
        3057 => 355503, // Carlos Lopez - MGA
        2868 => 415438, // Carlos Macià - VIL
        2331 => 324192, // Carlos Martín - ATM
        3020 => 421199, // Carlos Sánchez - RAC
        1251 => 250508, // Carmona - SEV
        2604 => 298343, // Carreira - CEL
        3163 => 3128976, // Caste - RAC
        3201 => 184757, // Castillo - ATM
        1033 => 264175, // Catena - OSA
        2933 => 320648, // Cepeda - ELC
        2888 => 415478, // Cestero - RMA
        3173 => 3101325, // Cheikh Thiam - VIL
        2353 => 186430, // Christensen - BAR
        3070 => 388602, // Chupe - MGA
        2987 => 300333, // Comas - RCD
        1031 => 240623, // Comesaña - VIL
        3223 => 300377, // Cortés - BAR
        2469 => 271129, // Costa - VIL
        230 => 134283, // Courtois - RMA
        2217 => 336809, // Cucho - BET
        3106 => 259910, // Cucurella - RMA
        3218 => 394958, // Curro  - GET
        3197 => 96970, // Cuti Romero - ATM
        3215 => 388076, // Córdoba - VAL
        2378 => 192893, // D. Cárdenas - RAY
        2881 => 368593, // D. Martínez - ATM
        2991 => 331742, // D. Villares - RCD
        3036 => 331533, // Dani Lorenzo - MGA
        2588 => 231618, // Danjuma - VAL
        184 => 196339, // David Soria - GET
        2736 => 406832, // Davinchi - GET
        886 => 260740, // De Frutos - RAY
        1070 => 157708, // De Galarreta - ATH
        3120 => 282086, // De Haas - VAL
        2725 => 389447, // De Las Sias - RAY
        2300 => 276841, // Dela - LEV
        2474 => 163068, // Denis Suárez - ALA
        2626 => 322908, // Deossa - BET
        2458 => 241117, // Diakhaby - VAL
        3158 => 213535, // Diarra - ALA
        2902 => 396751, // Diatta - VIL
        3031 => 396027, // Diego Diaz - RAC
        2366 => 193943, // Diego Rico - OSA
        3121 => 232803, // Dieng - VAL
        2457 => 165334, // Dimitrievski - VAL
        3143 => 402479, // Diomande - RMA
        3122 => 347033, // Djalo - ATH
        2661 => 181355, // Djene - GET
        2507 => 179211, // Dmitrovic - ESP
        2623 => 306520, // Dolan - ESP
        3044 => 324098, // Dotor - MGA
        3183 => 311969, // Dubasin - OSA
        3080 => 213248, // Dumfries - RMA
        3188 => 194553, // E. Ponce - ELC
        2980 => 286769, // Eddahchouri - RCD
        2823 => 403587, // Eder Aller - BAR
        2745 => 409486, // Edu Altozano - SEV
        3077 => 255272, // Edu Expósito - ESP
        3042 => 192430, // Einar - MGA
        2454 => 254270, // Ejuke - SEV
        1930 => 310682, // El Hilali - ESP
        2654 => 334111, // El-Abdellaoui - CEL
        3154 => 218122, // Enes Ünal - GET
        2354 => 128714, // Eric - BAR
        3018 => 400222, // Eriksson - RAC
        2700 => 346676, // Etta Eyong - LEV
        3089 => 300912, // Ez Abde - BET
        3199 => 359912, // F. Bernal - BET
        3027 => 367499, // Facu - RAC
        3195 => 21990, // Faye - CEL
        2295 => 240235, // Febas - CEL
        3147 => 301684, // Fer Niño - ELC
        1715 => 354334, // Fermín - BAR
        2603 => 279904, // Ferran Jutglà - CEL
        2819 => 388898, // Ferrer - GET
        2940 => 283827, // Fidalgo - BET
        2546 => 157739, // Foulquier - VAL
        3099 => 227714, // Fornals - BET
        2470 => 242710, // Foyth - VIL
        2429 => 213050, // Fran García - BET
        2579 => 362874, // Fran González - SEV
        2465 => 324505, // Fran Pérez - RAY
        3200 => 307186, // Francho - GET
        2934 => 248276, // Freeman - VIL
        3028 => 341736, // G. Puerta - RAC
        2939 => 273424, // G. Villar - ELC
        3167 => 3129044, // Galdin - LEV
        1221 => 323702, // Gavi - BAR
        302 => 178747, // Gayá - VAL
        356 => 156172, // Gerard - VIL
        2757 => 312015, // Gerard Martín - BAR
        3185 => 388803, // Gerenabarrena - ATH
        2988 => 181288, // Germán - RCD
        3074 => 297358, // Germán V. - ELC
        3187 => 362123, // Gijselhart - RCD
        1210 => 323838, // Giuliano - ATM
        3210 => 268782, // Gordon - BAR
        2865 => 406383, // Gorka Carrera - RSO
        2621 => 388601, // Gorrotxa - RSO
        3202 => 396611, // Goti - RSO
        2780 => 242739, // Grady Diangana - ELC
        3155 => 166396, // Grimaldo - ATM
        3085 => 213141, // Guedes - RSO
        270 => 260256, // Guevara - ALA
        2473 => 255889, // Gueye - VIL
        3128 => 212185, // Guido Rodríguez - VAL
        3175 => 422137, // Guillén - SEV
        3017 => 289439, // Guliashvili - RAC
        3130 => 119620, // Gulácsi - VIL
        856 => 254320, // Guridi - SEV
        1484 => 231905, // Guruzeta - ATH
        2850 => 324811, // H. González - CEL
        3184 => 362572, // H. Rincón - ATH
        3055 => 312338, // Haitam - MGA
        2561 => 257786, // Hancko - ATM
        2336 => 307019, // Herrando - OSA
        2372 => 236185, // Herrera - RSO
        3162 => 401377, // Hinojo - ESP
        3114 => 274911, // Hjulmand - ATM
        2078 => 188391, // Hugo Alvarez - CEL
        1191 => 267477, // Hugo Duro - VAL
        3212 => 417250, // Hugo Pérez - RAC
        2395 => 319114, // Hugo Sotelo - LEV
        2530 => 356000, // Huijsen - RMA
        2477 => 331047, // I. Akhomach - VIL
        2303 => 311054, // I. Romero - LEV
        2492 => 92800, // Iago Aspas - CEL
        2569 => 106326, // Ibañez - ALA
        3176 => 371543, // Ibra - SEV
        3181 => 3124560, // Ibra Drj - GET
        3227 => 407397, // Ifeanyi Ndukwe - LEV
        2651 => 292222, // Iker Losada - BET
        2876 => 415742, // Iker Muñoz - SEV
        1730 => 359154, // Iker Muñoz - OSA
        2607 => 297829, // Ilaix Moriba - CEL
        2713 => 144720, // Ionut Radu - CEL
        2456 => 365821, // Isaac - SEV
        3090 => 143564, // Isco - BET
        2382 => 264197, // Isi - RAY
        3205 => 330545, // Iván Martín - RAC
        3035 => 371564, // Izan M. - MGA
        91 => 214412, // Iñaki Williams - ATH
        3000 => 279699, // Iñigo - RAC
        3002 => 284699, // Iñigo Vicente - RAC
        2699 => 395479, // J. Ives Valou - GET
        2324 => 188398, // J. M. Giménez - ATM
        2321 => 177927, // J. Musso - ATM
        2678 => 319231, // J. Vertrouwd - RAY
        2461 => 189804, // J. Vázquez - VAL
        3216 => 3124771, // Jaume Durà - VAL
        3050 => 270610, // Jauregi - MGA
        2040 => 377792, // Jauregizar - ATH
        2329 => 231838, // Javi Galán - CEL
        3075 => 16340, // Javi Guerra - VAL
        3161 => 367946, // Javi Hernández - ESP
        2837 => 84349, // Javi Navarro - RMA
        2074 => 376147, // Javi Rodríguez - CEL
        2714 => 331586, // Javi Rueda - CEL
        2671 => 186024, // Jeremy Toljan - LEV
        2634 => 297362, // Joan García - BAR
        3034 => 180444, // Joaquín - MGA
        1950 => 307270, // Jofre - ESP
        3213 => 333214, // Johaneko - ATH
        2480 => 290802, // Johnny - ATM
        2656 => 366885, // Jon Martin - RSO
        2552 => 176312, // Jonny Otto - ALA
        3226 => 416511, // Jordi Pesquer - BAR
        3011 => 178237, // Jorge Salinas - RAC
        2587 => 214330, // Josan - ELC
        3172 => 397289, // Jose Angel - ESP
        3140 => 145565, // Juan Cruz - MGA
        1147 => 279738, // Juan Iglesias - SEV
        3204 => 3110513, // Juani - MGA
        2912 => 387493, // Julio Díaz - SEV
        3088 => 277206, // Julián Alvarez - ATM
        2627 => 269655, // Junior - BET
        2467 => 307648, // Júnior R. - VIL
        2905 => 415819, // K. Tunde - LEV
        2471 => 325553, // Kambwala - VIL
        3193 => 274197, // Kang-In Lee - ATM
        3186 => 331530, // Kevin - RCD
        2337 => 209580, // Kike Barja - OSA
        2479 => 334163, // Kike Salas - SEV
        2774 => 125749, // Kiko F. - GET
        3211 => 258923, // Kochorashvili - SEV
        2326 => 140791, // Koke - ATM
        3107 => 251634, // Konaté - RMA
        2938 => 149306, // Koski - ALA
        2355 => 231692, // Kounde - BAR
        1197 => 256587, // Kubo - RSO
        3104 => 362150, // Lamine Yamal - BAR
        3065 => 159317, // Laporte - ATH
        3072 => 297828, // Larrubia - MGA
        255 => 214888, // Le Normand - ATM
        2836 => 366886, // Lebarbier - RSO
        3082 => 140754, // Lejeune - RAY
        3109 => 300926, // Leo Román - RCD
        2363 => 344245, // Letacek - GET
        3171 => 381681, // Llorenç - ESP
        2415 => 181530, // Llorente - BET
        3049 => 301869, // Lobete - MGA
        3079 => 229645, // Lookman - ATM
        3178 => 268751, // Lozano - RAY
        2768 => 207236, // Lucas Boyé - ALA
        2962 => 324166, // Luismi Cruz - RCD
        2423 => 257237, // Lunin - RMA
        2348 => 301406, // M. Aguado - ELC
        2613 => 173749, // M. Dituro - ELC
        2965 => 259915, // M. Loureiro - RCD
        2639 => 353736, // M. Román - CEL
        3206 => 222661, // Maffeo - VAL
        3012 => 342654, // Maguette - RAC
        3174 => 150451, // Mandi - LEV
        3166 => 3129042, // Manel Usedo - LEV
        3133 => 228424, // Mangala - GET
        2999 => 300471, // Mantilla - RAC
        2687 => 356228, // Manu Bueno - SEV
        2794 => 390091, // Manu G. - BET
        3008 => 259839, // Manu Hernando - RAC
        2624 => 300985, // Manu Sánchez - LEV
        3214 => 3131879, // Manuel Ángel - SEV
        2784 => 376423, // Marc Bernal - BAR
        2864 => 362393, // Marc Jurado - VAL
        2562 => 332613, // Marc Pubill - ATM
        2418 => 242008, // Marc Roca - BET
        3165 => 3129043, // Marc Santos - LEV
        2862 => 396613, // Marchal - RSO
        3179 => 415410, // Marco Román - RAY
        3131 => 354621, // Marcos - ESP
        3123 => 146127, // Marcos Alonso - CEL
        67 => 206474, // Marcos Llorente - ATM
        2600 => 198832, // Mariano - ALA
        2640 => 357147, // Mario Martín - GET
        3221 => 330352, // Mario Rivas - RMA
        2995 => 297353, // Mario Soriano - RCD
        2319 => 393762, // Maroan - ATH
        2435 => 312011, // Marrero - RSO
        2601 => 340677, // Martim Neto - ELC
        3234 => 107960, // Matteo Prati - RAC
        2723 => 409475, // Mauro - OSA
        2887 => 379231, // Mañas - ALA
        3103 => 231388, // Mbappé - RMA
        2994 => 375390, // Mella - RCD
        2722 => 350351, // Mestre - RMA
        2961 => 421996, // Miguel Cubo - ATM
        3153 => 83494, // Miguel Rodríguez - ALA
        2746 => 375514, // Miguel Sierra - SEV
        2771 => 300002, // Mikautadze - VIL
        3144 => 396612, // Mikel Rodriguez - ALA
        680 => 169089, // Moi Gómez - OSA
        3150 => 173247, // Mojica - GET
        3086 => 307390, // Moleiro - VIL
        637 => 286176, // Moncayola - OSA
        2827 => 411235, // Monreal - ATH
        2958 => 421310, // Morcillo - ATM
        3127 => 361468, // Moscardo - ESP
        1959 => 318098, // Mouriño - VIL
        2742 => 409383, // Nacho Perez - LEV
        2543 => 301526, // Natan - BET
        2549 => 276996, // Navarro - ATH
        3083 => 312146, // Nico Williams - ATH
        3220 => 3101324, // Nizar El Jmili - VIL
        2853 => 368877, // Nordin Al-Lal - ELC
        2985 => 321112, // Noubi - RCD
        3159 => 305608, // Novoa - ALA
        2386 => 276847, // Nteka - RAY
        2930 => 260902, // Nuñez - ESP
        2628 => 269339, // O. Rey - LEV
        2318 => 291616, // O. Sancet - ATH
        2322 => 149622, // Oblak - ATM
        2955 => 406379, // Ochieng - RSO
        2445 => 317784, // Olasagasti - LEV
        3092 => 227765, // Olmo - BAR
        2761 => 294039, // Oluwaseyi - VIL
        2448 => 341367, // Oskarsson - RSO
        2789 => 377782, // Oso - SEV
        2922 => 336840, // Osorio - ELC
        2693 => 377768, // Otorbi - VAL
        3203 => 3110515, // Otu Jr - MGA
        3091 => 229018, // Oyarzabal - RSO
        2666 => 316040, // P. Campos - LEV
        3207 => 271151, // P. Martínez - MGA
        2397 => 138448, // Pablo Durán - CEL
        2712 => 399278, // Pablo García - BET
        3026 => 282672, // Pablo Ramón - RAC
        2439 => 297359, // Pacheco - RSO
        2673 => 384985, // Paco Cortes - LEV
        2644 => 347411, // Padilla - ATH
        2863 => 413671, // Panach - VAL
        2315 => 308254, // Paredes - ATH
        3231 => 279790, // Parrott - BET
        3059 => 310869, // Pastor - MGA
        2383 => 294228, // Pathé I. Ciss - RAY
        1775 => 368992, // Pau Cubarsí - BAR
        2698 => 368878, // Pau Navarro - VIL
        3100 => 250465, // Pedri - BAR
        2385 => 264078, // Pedro Díaz - RAY
        3139 => 361455, // Pedro Felipe - RAC
        3023 => 388805, // Peio Canales - ATH
        3157 => 386579, // Pelayo - RAY
        3087 => 210397, // Pepe - VIL
        2464 => 231603, // Pepelu - VAL
        2455 => 347359, // Peque - SEV
        2400 => 196914, // Pere Milla - ESP
        1938 => 260242, // Pol Lozano - ESP
        2342 => 253754, // Protesoni - ALA
        3098 => 346210, // Q. Hartman - ESP
        2963 => 275924, // Quagliata - RCD
        2302 => 267591, // R. Brugué - LEV
        2717 => 74630, // R. Mendoza - ATM
        2511 => 309838, // R. Terrats - GET
        2294 => 167297, // R.P. Bigas - ELC
        2560 => 253763, // Raba - VAL
        3038 => 357858, // Rafa Rodríguez - MGA
        2677 => 119332, // Rafa Romero - SEV
        3041 => 376166, // Rafita - MGA
        3048 => 292225, // Ramon - MGA
        2522 => 231050, // Raphinha - BAR
        3081 => 287224, // Ratiu - RAY
        2339 => 296163, // Raúl - OSA
        2923 => 303906, // Raúl Moro - OSA
        3054 => 319783, // Recio - MGA
        2703 => 318181, // Redondo - ELC
        2645 => 403371, // Rego - ATH
        274 => 217319, // Remiro - RSO
        2728 => 337011, // Renato Veiga - VIL
        3228 => 362752, // Requena - LEV
        2785 => 323737, // Riedel - ESP
        2977 => 279609, // Riki - RCD
        581 => 264177, // Rioja - VAL
        2548 => 70224, // Riquelme - BET
        2662 => 383912, // Risco - GET
        3145 => 322993, // Robbie Ure - SEV
        2520 => 318773, // Roberto - ESP
        3208 => 231828, // Rodri - BAR
        652 => 178328, // Rubén García - OSA
        2755 => 141438, // Ryan - LEV
        2428 => 169438, // Rüdiger - RMA
        3134 => 314403, // Saba Sazonov - GET
        2617 => 211770, // Sadiq - VAL
        3242 => 311163, // Saliba - VIL
        2525 => 184488, // Salinas - MGA
        3138 => 304213, // Sangante - SEV
        2873 => 415867, // Santos - OSA
        3217 => 360096, // Sato - VAL
        3137 => 284854, // Satriano - GET
        1989 => 265993, // Sergio Gómez - RSO
        644 => 191360, // Sergio Herrera - OSA
        3024 => 409318, // Sergio - RAC
        988 => 200910, // Sivera - ALA
        2390 => 195790, // Starfelt - CEL
        2753 => 222037, // Suazo - SEV
        2444 => 306263, // Sucic - RSO
        2749 => 346534, // Swiderski - ALA
        2783 => 131634, // Szczesny - BAR
        3078 => 181757, // T. Martínez - ALA
        2941 => 259916, // T. Morente - ELC
        1337 => 262491, // Tenaglia - ALA
        3230 => 339195, // Thiago - LEV
        3170 => 420443, // Timera - ESP
        873 => 170154, // Torró - OSA
        2531 => 223532, // Trent - RMA
        3198 => 276067, // Tsitaishvili - RAY
        1215 => 297371, // Turrientes - RSO
        1991 => 330552, // Tárrega - VAL
        2612 => 248681, // Ugrinic - VAL
        3192 => 402185, // Umaru - ELC
        2384 => 203711, // Unai Lopez - RAY
        68 => 222375, // Unai Simón - ATH
        2622 => 307915, // Urko - ESP
        2596 => 265995, // V. Chust - ELC
        3156 => 318084, // Valentini - ALA
        2609 => 336480, // Valentín Gómez - BET
        2430 => 235818, // Valverde - RMA
        3232 => 324582, // Van Oevelen - VAL
        3177 => 277314, // Vanja Drkusic - ESP
        3117 => 259513, // Vargas - SEV
        3022 => 222365, // Villalibre - RAC
        3102 => 252107, // Vini Jr. - RMA
        2759 => 159135, // Vlachodimos - SEV
        2670 => 203680, // Víctor G. - LEV
        2394 => 322652, // Williot - CEL
        2885 => 415276, // Xanet Olaiz - ALA
        2843 => 403582, // Xavi Espart - BAR
        2990 => 157568, // Ximo Navarro - RCD
        3152 => 390389, // Y. Zabiri - RAC
        3229 => 3102194, // Yanis Musuayi - LEV
        77 => 217225, // Yeray - ATH
        3076 => 312392, // Yeremay - RCD
        2206 => 384643, // Yoel Lago - CEL
        2568 => 382532, // Youssef - ALA
        74 => 133768, // Yuri - ATH
        2924 => 300111, // Zaid Romero - GET
        2446 => 307487, // Zakharyan - RSO
        265 => 235693, // Zubeldia - RSO
        2533 => 310637, // Á. Carreras - RMA
        2711 => 395941, // Á. Ortiz - BET
        3191 => 3129647, // Á. Padilla - ELC
        2541 => 283892, // Á. Valles - BET
        2534 => 297373, // Álex Baena - ATM
        1218 => 323703, // Álex Balde - BAR
        1029 => 125200, // Álvaro García - RAY
        2346 => 367430, // Álvaro Núñez - CEL
        2932 => 374621, // Ángel Pérez - ALA
        3219 => 396387, // Óscar López - GET
        1103 => 264217, // Óscar Valentín - RAY
    ];

    public function handle(): int
    {
        $season = Season::current();
        $teamIds = $season->teams()->select('teams.id');

        $unlinkedPlayers = Player::query()
            ->whereIn('team_id', $teamIds)
            ->whereNull('match_data_id')
            ->whereNotNull('fantasy_id')
            ->whereHas('seasons', fn ($query) => $query
                ->where('season_id', $season->id)
                ->where('position', '!=', PlayerPosition::Coach))
            ->get();

        ['linked' => $linked, 'lineupsBackfilled' => $lineupsBackfilled, 'eventsBackfilled' => $eventsBackfilled] = $this->linkFromMap($unlinkedPlayers, self::PLAYER_MAP);

        // Also re-sweep players linked in a previous run: fixture_lineups/fixture_events
        // for their match_data_id can still arrive after that run (e.g. a later matchday),
        // and only this pass — not the one above, which only touches newly-linked players — picks those up.
        $alreadyLinkedPlayers = Player::query()
            ->whereIn('team_id', $teamIds)
            ->whereNotNull('match_data_id')
            ->get();

        foreach ($alreadyLinkedPlayers as $player) {
            ['lineupsBackfilled' => $lineups, 'eventsBackfilled' => $events] = $this->backfillFixtures($player, $player->match_data_id);
            $lineupsBackfilled += $lineups;
            $eventsBackfilled += $events;
        }

        $this->info("{$linked} players linked, {$lineupsBackfilled} fixture lineups backfilled, {$eventsBackfilled} fixture events backfilled.");

        $remaining = $unlinkedPlayers->count() - $linked;

        if ($remaining > 0) {
            $this->warn("{$remaining} players still unresolved — run season:list-unlinked-match-data-players to review.");
        }

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, Player>  $players
     * @param  array<int, int>  $map  fantasy_id => worldcup26.ir athlete id
     * @return array{linked: int, lineupsBackfilled: int, eventsBackfilled: int}
     */
    private function linkFromMap(Collection $players, array $map): array
    {
        $linked = 0;
        $lineupsBackfilled = 0;
        $eventsBackfilled = 0;

        foreach ($players as $player) {
            $matchDataId = $map[$player->fantasy_id] ?? null;

            if ($matchDataId === null) {
                continue;
            }

            $player->update(['match_data_id' => $matchDataId]);
            $linked++;

            ['lineupsBackfilled' => $lineups, 'eventsBackfilled' => $events] = $this->backfillFixtures($player, $matchDataId);
            $lineupsBackfilled += $lineups;
            $eventsBackfilled += $events;
        }

        return ['linked' => $linked, 'lineupsBackfilled' => $lineupsBackfilled, 'eventsBackfilled' => $eventsBackfilled];
    }

    /**
     * @return array{lineupsBackfilled: int, eventsBackfilled: int}
     */
    private function backfillFixtures(Player $player, int $matchDataId): array
    {
        $lineupsBackfilled = FixtureLineup::query()
            ->where('match_data_id', $matchDataId)
            ->whereNull('player_id')
            ->update(['player_id' => $player->id, 'unresolved_name' => null]);

        $eventsBackfilled = FixtureEvent::query()
            ->where('match_data_id', $matchDataId)
            ->whereNull('player_id')
            ->update(['player_id' => $player->id, 'unresolved_name' => null]);

        return ['lineupsBackfilled' => $lineupsBackfilled, 'eventsBackfilled' => $eventsBackfilled];
    }
}
