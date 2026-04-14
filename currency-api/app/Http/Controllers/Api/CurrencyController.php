<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class CurrencyController extends Controller
{
    /**
     * Free Exchange Rate API base URL
     * Using: https://open.er-api.com (free, no key needed)
     * Alternative: https://api.exchangerate-api.com/v4/latest/USD
     */
    private string $apiBaseUrl = 'https://open.er-api.com/v6';
    private int $cacheDuration = 3600; // 1 hour cache

    /**
     * GET /api/v1/currencies
     * Returns all supported currencies with names and symbols
     */
    public function getCurrencies()
    {
        $currencies = Cache::remember('currencies_list', $this->cacheDuration, function () {
            return $this->getAllCurrencies();
        });

        return response()->json([
            'success' => true,
            'data' => $currencies,
            'total' => count($currencies),
        ]);
    }

    /**
     * POST /api/v1/convert
     * Convert an amount from one currency to another
     *
     * Body: { from: "USD", to: "EUR", amount: 100 }
     */
    public function convert(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'from'   => 'required|string|size:3',
            'to'     => 'required|string|size:3',
            'amount' => 'required|numeric|min:0.01|max:999999999',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        $from   = strtoupper($request->from);
        $to     = strtoupper($request->to);
        $amount = (float) $request->amount;

        $rates = $this->fetchRates($from);

        if (!$rates || !isset($rates[$to])) {
            return response()->json([
                'success' => false,
                'message' => "Currency '{$to}' is not supported.",
            ], 404);
        }

        $rate            = $rates[$to];
        $convertedAmount = round($amount * $rate, 6);

        return response()->json([
            'success'          => true,
            'data'             => [
                'from'             => $from,
                'to'               => $to,
                'amount'           => $amount,
                'converted_amount' => $convertedAmount,
                'rate'             => $rate,
                'inverse_rate'     => round(1 / $rate, 6),
                'formatted'        => number_format($convertedAmount, 2),
            ],
            'last_updated' => now()->toIso8601String(),
        ]);
    }

    /**
     * GET /api/v1/rates/{base}
     * Get all exchange rates for a base currency
     */
    public function getRates(string $base)
    {
        $base  = strtoupper($base);
        $rates = $this->fetchRates($base);

        if (!$rates) {
            return response()->json([
                'success' => false,
                'message' => "Could not fetch rates for '{$base}'.",
            ], 500);
        }

        // Sort by currency code
        ksort($rates);

        return response()->json([
            'success'      => true,
            'base'         => $base,
            'data'         => $rates,
            'last_updated' => now()->toIso8601String(),
        ]);
    }

    /**
     * GET /api/v1/historical/{base}/{target}
     * Get simulated 7-day historical rates
     * Note: Real historical data requires a paid API key.
     * This demo generates realistic simulated data.
     */
    public function getHistorical(string $base, string $target)
    {
        $base   = strtoupper($base);
        $target = strtoupper($target);

        $rates  = $this->fetchRates($base);

        if (!$rates || !isset($rates[$target])) {
            return response()->json([
                'success' => false,
                'message' => "Unsupported currency pair.",
            ], 404);
        }

        $currentRate = $rates[$target];
        $historical  = [];

        // Generate 7 days of simulated historical data around the current rate
        for ($i = 6; $i >= 0; $i--) {
            $date        = now()->subDays($i)->format('Y-m-d');
            $variation   = $currentRate * (mt_rand(-200, 200) / 10000); // ±2% variation
            $historical[] = [
                'date' => $date,
                'rate' => round($currentRate + $variation, 6),
            ];
        }

        return response()->json([
            'success' => true,
            'base'    => $base,
            'target'  => $target,
            'data'    => $historical,
        ]);
    }

    /**
     * POST /api/v1/convert/bulk
     * Convert one amount to multiple currencies at once
     *
     * Body: { from: "USD", amount: 100, to: ["EUR", "GBP", "JPY"] }
     */
    public function bulkConvert(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'from'    => 'required|string|size:3',
            'amount'  => 'required|numeric|min:0.01',
            'to'      => 'required|array|min:1|max:20',
            'to.*'    => 'required|string|size:3',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        $from   = strtoupper($request->from);
        $amount = (float) $request->amount;
        $targets = array_map('strtoupper', $request->to);

        $rates   = $this->fetchRates($from);
        $results = [];

        foreach ($targets as $to) {
            if (isset($rates[$to])) {
                $rate      = $rates[$to];
                $results[] = [
                    'currency'         => $to,
                    'rate'             => $rate,
                    'converted_amount' => round($amount * $rate, 6),
                    'formatted'        => number_format($amount * $rate, 2),
                ];
            }
        }

        return response()->json([
            'success'      => true,
            'from'         => $from,
            'amount'       => $amount,
            'conversions'  => $results,
            'last_updated' => now()->toIso8601String(),
        ]);
    }

    // ─── Private Helpers ────────────────────────────────────────────────────────

    private function fetchRates(string $base): ?array
    {
        $cacheKey = "rates_{$base}";

        return Cache::remember($cacheKey, $this->cacheDuration, function () use ($base) {
            try {
                $response = Http::timeout(10)
                    ->get("{$this->apiBaseUrl}/latest/{$base}");

                if ($response->successful()) {
                    $data = $response->json();
                    return $data['rates'] ?? null;
                }
            } catch (\Exception $e) {
                \Log::error("Currency API error: " . $e->getMessage());
            }
            return null;
        });
    }

    private function getAllCurrencies(): array
    {
        // Comprehensive list of currency codes with names and symbols
        return [
            'AED' => ['name' => 'UAE Dirham',              'symbol' => 'د.إ', 'flag' => '🇦🇪'],
            'AFN' => ['name' => 'Afghan Afghani',           'symbol' => '؋',   'flag' => '🇦🇫'],
            'ALL' => ['name' => 'Albanian Lek',             'symbol' => 'L',   'flag' => '🇦🇱'],
            'AMD' => ['name' => 'Armenian Dram',            'symbol' => '֏',   'flag' => '🇦🇲'],
            'ANG' => ['name' => 'Netherlands Antillean Guilder', 'symbol' => 'ƒ', 'flag' => '🇳🇱'],
            'AOA' => ['name' => 'Angolan Kwanza',           'symbol' => 'Kz',  'flag' => '🇦🇴'],
            'ARS' => ['name' => 'Argentine Peso',           'symbol' => '$',   'flag' => '🇦🇷'],
            'AUD' => ['name' => 'Australian Dollar',        'symbol' => 'A$',  'flag' => '🇦🇺'],
            'AWG' => ['name' => 'Aruban Florin',            'symbol' => 'ƒ',   'flag' => '🇦🇼'],
            'AZN' => ['name' => 'Azerbaijani Manat',        'symbol' => '₼',   'flag' => '🇦🇿'],
            'BAM' => ['name' => 'Bosnia-Herzegovina Convertible Mark', 'symbol' => 'KM', 'flag' => '🇧🇦'],
            'BBD' => ['name' => 'Barbadian Dollar',         'symbol' => 'Bds$','flag' => '🇧🇧'],
            'BDT' => ['name' => 'Bangladeshi Taka',         'symbol' => '৳',   'flag' => '🇧🇩'],
            'BGN' => ['name' => 'Bulgarian Lev',            'symbol' => 'лв',  'flag' => '🇧🇬'],
            'BHD' => ['name' => 'Bahraini Dinar',           'symbol' => '.د.ب','flag' => '🇧🇭'],
            'BRL' => ['name' => 'Brazilian Real',           'symbol' => 'R$',  'flag' => '🇧🇷'],
            'BSD' => ['name' => 'Bahamian Dollar',          'symbol' => 'B$',  'flag' => '🇧🇸'],
            'BTN' => ['name' => 'Bhutanese Ngultrum',       'symbol' => 'Nu',  'flag' => '🇧🇹'],
            'BWP' => ['name' => 'Botswanan Pula',           'symbol' => 'P',   'flag' => '🇧🇼'],
            'BYN' => ['name' => 'Belarusian Ruble',         'symbol' => 'Br',  'flag' => '🇧🇾'],
            'BZD' => ['name' => 'Belize Dollar',            'symbol' => 'BZ$', 'flag' => '🇧🇿'],
            'CAD' => ['name' => 'Canadian Dollar',          'symbol' => 'C$',  'flag' => '🇨🇦'],
            'CHF' => ['name' => 'Swiss Franc',              'symbol' => 'Fr',  'flag' => '🇨🇭'],
            'CLP' => ['name' => 'Chilean Peso',             'symbol' => '$',   'flag' => '🇨🇱'],
            'CNY' => ['name' => 'Chinese Yuan',             'symbol' => '¥',   'flag' => '🇨🇳'],
            'COP' => ['name' => 'Colombian Peso',           'symbol' => '$',   'flag' => '🇨🇴'],
            'CRC' => ['name' => 'Costa Rican Colón',        'symbol' => '₡',   'flag' => '🇨🇷'],
            'CUP' => ['name' => 'Cuban Peso',               'symbol' => '$',   'flag' => '🇨🇺'],
            'CVE' => ['name' => 'Cape Verdean Escudo',      'symbol' => '$',   'flag' => '🇨🇻'],
            'CZK' => ['name' => 'Czech Koruna',             'symbol' => 'Kč',  'flag' => '🇨🇿'],
            'DJF' => ['name' => 'Djiboutian Franc',         'symbol' => 'Fdj', 'flag' => '🇩🇯'],
            'DKK' => ['name' => 'Danish Krone',             'symbol' => 'kr',  'flag' => '🇩🇰'],
            'DOP' => ['name' => 'Dominican Peso',           'symbol' => 'RD$', 'flag' => '🇩🇴'],
            'DZD' => ['name' => 'Algerian Dinar',           'symbol' => 'دج',  'flag' => '🇩🇿'],
            'EGP' => ['name' => 'Egyptian Pound',           'symbol' => 'E£',  'flag' => '🇪🇬'],
            'ERN' => ['name' => 'Eritrean Nakfa',           'symbol' => 'Nfk', 'flag' => '🇪🇷'],
            'ETB' => ['name' => 'Ethiopian Birr',           'symbol' => 'Br',  'flag' => '🇪🇹'],
            'EUR' => ['name' => 'Euro',                     'symbol' => '€',   'flag' => '🇪🇺'],
            'FJD' => ['name' => 'Fijian Dollar',            'symbol' => 'FJ$', 'flag' => '🇫🇯'],
            'GBP' => ['name' => 'British Pound',            'symbol' => '£',   'flag' => '🇬🇧'],
            'GEL' => ['name' => 'Georgian Lari',            'symbol' => '₾',   'flag' => '🇬🇪'],
            'GHS' => ['name' => 'Ghanaian Cedi',            'symbol' => '₵',   'flag' => '🇬🇭'],
            'GMD' => ['name' => 'Gambian Dalasi',           'symbol' => 'D',   'flag' => '🇬🇲'],
            'GTQ' => ['name' => 'Guatemalan Quetzal',       'symbol' => 'Q',   'flag' => '🇬🇹'],
            'HKD' => ['name' => 'Hong Kong Dollar',         'symbol' => 'HK$', 'flag' => '🇭🇰'],
            'HNL' => ['name' => 'Honduran Lempira',         'symbol' => 'L',   'flag' => '🇭🇳'],
            'HRK' => ['name' => 'Croatian Kuna',            'symbol' => 'kn',  'flag' => '🇭🇷'],
            'HTG' => ['name' => 'Haitian Gourde',           'symbol' => 'G',   'flag' => '🇭🇹'],
            'HUF' => ['name' => 'Hungarian Forint',         'symbol' => 'Ft',  'flag' => '🇭🇺'],
            'IDR' => ['name' => 'Indonesian Rupiah',        'symbol' => 'Rp',  'flag' => '🇮🇩'],
            'ILS' => ['name' => 'Israeli New Shekel',       'symbol' => '₪',   'flag' => '🇮🇱'],
            'INR' => ['name' => 'Indian Rupee',             'symbol' => '₹',   'flag' => '🇮🇳'],
            'IQD' => ['name' => 'Iraqi Dinar',              'symbol' => 'ع.د', 'flag' => '🇮🇶'],
            'IRR' => ['name' => 'Iranian Rial',             'symbol' => '﷼',   'flag' => '🇮🇷'],
            'ISK' => ['name' => 'Icelandic Króna',          'symbol' => 'kr',  'flag' => '🇮🇸'],
            'JMD' => ['name' => 'Jamaican Dollar',          'symbol' => 'J$',  'flag' => '🇯🇲'],
            'JOD' => ['name' => 'Jordanian Dinar',          'symbol' => 'JD',  'flag' => '🇯🇴'],
            'JPY' => ['name' => 'Japanese Yen',             'symbol' => '¥',   'flag' => '🇯🇵'],
            'KES' => ['name' => 'Kenyan Shilling',          'symbol' => 'KSh', 'flag' => '🇰🇪'],
            'KGS' => ['name' => 'Kyrgystani Som',           'symbol' => 'с',   'flag' => '🇰🇬'],
            'KHR' => ['name' => 'Cambodian Riel',           'symbol' => '៛',   'flag' => '🇰🇭'],
            'KRW' => ['name' => 'South Korean Won',         'symbol' => '₩',   'flag' => '🇰🇷'],
            'KWD' => ['name' => 'Kuwaiti Dinar',            'symbol' => 'KD',  'flag' => '🇰🇼'],
            'KZT' => ['name' => 'Kazakhstani Tenge',        'symbol' => '₸',   'flag' => '🇰🇿'],
            'LAK' => ['name' => 'Laotian Kip',              'symbol' => '₭',   'flag' => '🇱🇦'],
            'LBP' => ['name' => 'Lebanese Pound',           'symbol' => 'ل.ل', 'flag' => '🇱🇧'],
            'LKR' => ['name' => 'Sri Lankan Rupee',         'symbol' => 'Rs',  'flag' => '🇱🇰'],
            'LYD' => ['name' => 'Libyan Dinar',             'symbol' => 'LD',  'flag' => '🇱🇾'],
            'MAD' => ['name' => 'Moroccan Dirham',          'symbol' => 'MAD', 'flag' => '🇲🇦'],
            'MDL' => ['name' => 'Moldovan Leu',             'symbol' => 'L',   'flag' => '🇲🇩'],
            'MKD' => ['name' => 'Macedonian Denar',         'symbol' => 'ден', 'flag' => '🇲🇰'],
            'MMK' => ['name' => 'Myanmar Kyat',             'symbol' => 'K',   'flag' => '🇲🇲'],
            'MNT' => ['name' => 'Mongolian Tugrik',         'symbol' => '₮',   'flag' => '🇲🇳'],
            'MOP' => ['name' => 'Macanese Pataca',          'symbol' => 'P',   'flag' => '🇲🇴'],
            'MUR' => ['name' => 'Mauritian Rupee',          'symbol' => '₨',   'flag' => '🇲🇺'],
            'MVR' => ['name' => 'Maldivian Rufiyaa',        'symbol' => 'Rf',  'flag' => '🇲🇻'],
            'MWK' => ['name' => 'Malawian Kwacha',          'symbol' => 'MK',  'flag' => '🇲🇼'],
            'MXN' => ['name' => 'Mexican Peso',             'symbol' => '$',   'flag' => '🇲🇽'],
            'MYR' => ['name' => 'Malaysian Ringgit',        'symbol' => 'RM',  'flag' => '🇲🇾'],
            'NAD' => ['name' => 'Namibian Dollar',          'symbol' => 'N$',  'flag' => '🇳🇦'],
            'NGN' => ['name' => 'Nigerian Naira',           'symbol' => '₦',   'flag' => '🇳🇬'],
            'NIO' => ['name' => 'Nicaraguan Córdoba',       'symbol' => 'C$',  'flag' => '🇳🇮'],
            'NOK' => ['name' => 'Norwegian Krone',          'symbol' => 'kr',  'flag' => '🇳🇴'],
            'NPR' => ['name' => 'Nepalese Rupee',           'symbol' => '₨',   'flag' => '🇳🇵'],
            'NZD' => ['name' => 'New Zealand Dollar',       'symbol' => 'NZ$', 'flag' => '🇳🇿'],
            'OMR' => ['name' => 'Omani Rial',               'symbol' => 'ر.ع.','flag' => '🇴🇲'],
            'PAB' => ['name' => 'Panamanian Balboa',        'symbol' => 'B/.',  'flag' => '🇵🇦'],
            'PEN' => ['name' => 'Peruvian Sol',             'symbol' => 'S/.',  'flag' => '🇵🇪'],
            'PHP' => ['name' => 'Philippine Peso',          'symbol' => '₱',   'flag' => '🇵🇭'],
            'PKR' => ['name' => 'Pakistani Rupee',          'symbol' => '₨',   'flag' => '🇵🇰'],
            'PLN' => ['name' => 'Polish Zloty',             'symbol' => 'zł',  'flag' => '🇵🇱'],
            'PYG' => ['name' => 'Paraguayan Guarani',       'symbol' => '₲',   'flag' => '🇵🇾'],
            'QAR' => ['name' => 'Qatari Rial',              'symbol' => 'ر.ق', 'flag' => '🇶🇦'],
            'RON' => ['name' => 'Romanian Leu',             'symbol' => 'lei', 'flag' => '🇷🇴'],
            'RSD' => ['name' => 'Serbian Dinar',            'symbol' => 'din', 'flag' => '🇷🇸'],
            'RUB' => ['name' => 'Russian Ruble',            'symbol' => '₽',   'flag' => '🇷🇺'],
            'RWF' => ['name' => 'Rwandan Franc',            'symbol' => 'FRw', 'flag' => '🇷🇼'],
            'SAR' => ['name' => 'Saudi Riyal',              'symbol' => '﷼',   'flag' => '🇸🇦'],
            'SCR' => ['name' => 'Seychellois Rupee',        'symbol' => '₨',   'flag' => '🇸🇨'],
            'SDG' => ['name' => 'Sudanese Pound',           'symbol' => 'ج.س.','flag' => '🇸🇩'],
            'SEK' => ['name' => 'Swedish Krona',            'symbol' => 'kr',  'flag' => '🇸🇪'],
            'SGD' => ['name' => 'Singapore Dollar',         'symbol' => 'S$',  'flag' => '🇸🇬'],
            'SLL' => ['name' => 'Sierra Leonean Leone',     'symbol' => 'Le',  'flag' => '🇸🇱'],
            'SOS' => ['name' => 'Somali Shilling',          'symbol' => 'Sh',  'flag' => '🇸🇴'],
            'SRD' => ['name' => 'Surinamese Dollar',        'symbol' => '$',   'flag' => '🇸🇷'],
            'SYP' => ['name' => 'Syrian Pound',             'symbol' => '£',   'flag' => '🇸🇾'],
            'SZL' => ['name' => 'Swazi Lilangeni',          'symbol' => 'L',   'flag' => '🇸🇿'],
            'THB' => ['name' => 'Thai Baht',                'symbol' => '฿',   'flag' => '🇹🇭'],
            'TJS' => ['name' => 'Tajikistani Somoni',       'symbol' => 'SM',  'flag' => '🇹🇯'],
            'TMT' => ['name' => 'Turkmenistani Manat',      'symbol' => 'T',   'flag' => '🇹🇲'],
            'TND' => ['name' => 'Tunisian Dinar',           'symbol' => 'د.ت', 'flag' => '🇹🇳'],
            'TRY' => ['name' => 'Turkish Lira',             'symbol' => '₺',   'flag' => '🇹🇷'],
            'TTD' => ['name' => 'Trinidad & Tobago Dollar', 'symbol' => 'TT$', 'flag' => '🇹🇹'],
            'TWD' => ['name' => 'Taiwan New Dollar',        'symbol' => 'NT$', 'flag' => '🇹🇼'],
            'TZS' => ['name' => 'Tanzanian Shilling',       'symbol' => 'Sh',  'flag' => '🇹🇿'],
            'UAH' => ['name' => 'Ukrainian Hryvnia',        'symbol' => '₴',   'flag' => '🇺🇦'],
            'UGX' => ['name' => 'Ugandan Shilling',         'symbol' => 'Sh',  'flag' => '🇺🇬'],
            'USD' => ['name' => 'US Dollar',                'symbol' => '$',   'flag' => '🇺🇸'],
            'UYU' => ['name' => 'Uruguayan Peso',           'symbol' => '$U',  'flag' => '🇺🇾'],
            'UZS' => ['name' => 'Uzbekistani Som',          'symbol' => 'so\'m','flag' => '🇺🇿'],
            'VES' => ['name' => 'Venezuelan Bolívar',       'symbol' => 'Bs.S','flag' => '🇻🇪'],
            'VND' => ['name' => 'Vietnamese Dong',          'symbol' => '₫',   'flag' => '🇻🇳'],
            'XAF' => ['name' => 'Central African CFA Franc','symbol' => 'FCFA','flag' => '🌍'],
            'XCD' => ['name' => 'East Caribbean Dollar',    'symbol' => 'EC$', 'flag' => '🌎'],
            'XOF' => ['name' => 'West African CFA Franc',   'symbol' => 'CFA', 'flag' => '🌍'],
            'YER' => ['name' => 'Yemeni Rial',              'symbol' => '﷼',   'flag' => '🇾🇪'],
            'ZAR' => ['name' => 'South African Rand',       'symbol' => 'R',   'flag' => '🇿🇦'],
            'ZMW' => ['name' => 'Zambian Kwacha',           'symbol' => 'ZK',  'flag' => '🇿🇲'],
        ];
    }
}
