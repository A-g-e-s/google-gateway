# ages/google-gateway

PHP klient pro **Google Ads API** (REST, `https://googleads.googleapis.com`, verze `v26`).
Zaměřený na **náklady a statistiky** (útrata, prokliky, zobrazení, konverze, PNO…) — celkem, po obdobích i po kampaních.

- REST/JSON + dotazovací jazyk GAQL, bez gRPC a bez oficiální knihovny `googleads/google-ads-php`
- Autentizace OAuth 2.0: refresh token → access token (platnost ~1 h, klient ho cachuje a reusuje)
- Opakování při 429 / 503 (`Retry-After` nebo exponenciální čekání)
- PHP 8.4+, Guzzle, framework-agnostic (volitelná PSR-16 cache pro sdílení tokenu mezi requesty)

## Instalace

```bash
composer require ages/google-gateway
```

## Přístupové údaje

Od **9. 9. 2026 Google zrušil developer tokeny**. Přístup k API (a jeho úroveň) teď patří
**Google Cloud projektu**, ve kterém je OAuth klient — vše se nastavuje v [Google Cloud Console](https://console.cloud.google.com/).
Hlavičku `developer-token` API ignoruje; v konfiguraci je jen volitelná (`developerToken`) pro starší nástroje.

Potřebné údaje: `clientId`, `clientSecret`, `refreshToken` + ID účtu (`customerId`).

### 1. Projekt a Google Ads API

1. **Nový projekt** (např. *Wolfio Ads*) — samostatný, ať se úroveň přístupu a kvóty nemíchají s jinými API.
2. **APIs & Services › Library** › *Google Ads API* › **Enable**. Projekt tím dostane úroveň **Test** (jen testovací účty).
3. **Google Ads API › Overview** (`/google/ads-apis/overview`) › *Upgrade access level* › **Apply for access** — úroveň **Explorer**
   (produkční účty, 2 880 operací/den, Google schvaluje většinou automaticky). Vyšší úrovně:

| Úroveň | Účty | Limit operací/den |
|--------|------|-------------------|
| Test | jen testovací | 15 000 (test) |
| Explorer | testovací i produkční | 2 880 |
| Basic | testovací i produkční | 15 000 |
| Standard | testovací i produkční | neomezeno |

> Pro denní stahování nákladů pár účtů stačí Explorer (jeden dotaz = jedna operace).

### 2. OAuth klient

1. **Google Auth Platform › Overview › Get started**: název aplikace, support e-mail, typ **External**
   (Internal jen u Google Workspace), kontaktní e-mail, souhlas s *User Data Policy*.
2. **Clients › Create client** › typ **Desktop app** → `clientId` a `clientSecret`.
   **Secret se zobrazí jen jednou** — hned ho ulož (nebo stáhni JSON).
3. **Audience**: v režimu *Testing* přidej mezi **Test users** účet, který má přístup ke Google Ads,
   ale **refresh token pak po 7 dnech vyprší**. Pro trvalý provoz aplikaci **Publish app** (*In production*) —
   u neověřené aplikace se při souhlasu zobrazí varování „Google hasn't verified this app“ (*Advanced › Go to …*), funguje to do 100 uživatelů a ověření kvůli vlastnímu účtu není potřeba.

### 3. Refresh token a ID účtu

| Údaj | Kde ho získat |
|------|---------------|
| `refreshToken` | Jednorázově přes `OAuth` helper (níže) pod Google účtem, který má přístup k účtu Google Ads. |
| `customerId` | ID účtu Google Ads vpravo nahoře v rozhraní (`123-456-7890`, pomlčky nevadí), případně `listAccessibleCustomers()`. |
| `loginCustomerId` | ID manažerského účtu (MCC), **pokud** máš k účtu přístup přes MCC. Při přímém přístupu nevyplňuj. |

### Získání refresh tokenu

```php
use Ages\GoogleGateway\Auth\OAuth;

// 1) otevři URL v prohlížeči a povol přístup
echo OAuth::authorizationUrl($clientId), PHP_EOL;

// 2) prohlížeč přesměruje na http://127.0.0.1/?code=…  (stránka se nenačte, to nevadí)
//    zkopíruj hodnotu parametru code z adresního řádku

// 3) vyměň code za refresh token a ulož ho do konfigurace
echo OAuth::exchangeCode($clientId, $clientSecret, $code), PHP_EOL;
```

> Access token má platnost 1 hodinu. Klient ho získá jednou a reusuje; s PSR-16 cache i mezi requesty.

## Použití

```php
use Ages\GoogleGateway\Config\GoogleAdsConfig;
use Ages\GoogleGateway\GoogleGateway;

$gateway = new GoogleGateway(new GoogleAdsConfig(
    clientId: '….apps.googleusercontent.com',
    clientSecret: '…',
    refreshToken: '…',
    customerId: '123-456-7890',
) /*, $psr16cache */);
```

### Náklady za období

Data se vrací rovnou (žádné čekání na report jako u Skliku). Období je po dnech včetně krajních
v časové zóně účtu. Peníze jsou v měně účtu (`getCustomer()->currencyCode`).

```php
$m = $gateway->getCosts(new DateTimeImmutable('2026-09-01'), new DateTimeImmutable('2026-09-30'));   // Metrics
echo $m->cost;          // útrata
echo $m->impressions;   // zobrazení
echo $m->clicks;        // prokliky
echo $m->avgCpc();      // průměrné CPC
echo $m->ctr();         // CTR (0–1)
echo $m->conversions, ' ', $m->conversionsValue, ' ', $m->pno(), ' ', $m->roas();
```

### Rozpad po obdobích

API seskupuje samo (`segments.date|week|month|quarter|year`); řádek nese datum začátku období
(den, pondělí, 1. den měsíce / kvartálu / roku). Období bez zobrazení chybí.

```php
use Ages\GoogleGateway\Enum\Granularity;

foreach ($gateway->getCostsBreakdown($from, $to, Granularity::Monthly) as $row) {   // list<CostRow>
    echo $row->date->format('Y-m'), ': ', $row->metrics->cost, ' Kč, ', $row->metrics->clicks, ' prokliků', PHP_EOL;
}
```

### Náklady po kampaních

Seřazené od nejvyšší útraty — hodí se pro rozdělení např. na Shopping / Performance Max a ostatní kampaně.

```php
foreach ($gateway->getCampaignCosts($from, $to) as $campaign) {   // list<CampaignCost>
    echo $campaign->name, ' [', $campaign->channelType, ']: ', $campaign->metrics->cost, PHP_EOL;
    // channelType: SEARCH, SHOPPING, PERFORMANCE_MAX, DISPLAY, VIDEO, DEMAND_GEN…
}
```

### Účet a rozpočet

```php
$customer = $gateway->ads()->getCustomer();          // id, name, currencyCode, timeZone
$ids = $gateway->ads()->listAccessibleCustomers();   // účty dostupné pro uživatele refresh tokenu

foreach ($gateway->ads()->getAccountBudgets() as $budget) {   // list<AccountBudget>
    echo $budget->spendingLimit, ' / ', $budget->amountServed, ' → zbývá ', $budget->remaining() ?? '∞', PHP_EOL;
}
```

> **Kredit:** Google na rozdíl od Skliku nemá předplacený kredit dostupný přes API. Account budget
> existuje jen u účtů s **měsíční fakturací**; účet placený automaticky kartou ho nemá (prázdný seznam)
> a zůstatek ručních plateb API nevrací.

### Vlastní GAQL dotaz

```php
$rows = $gateway->ads()->search(
    "SELECT ad_group.name, metrics.cost_micros FROM ad_group WHERE segments.date DURING LAST_7_DAYS",
);
```

Pole a jejich kombinace: [Google Ads Query Builder](https://developers.google.com/google-ads/api/fields/v26/overview_query_builder).
Řádky mají camelCase klíče (`metrics.costMicros`), int64 hodnoty přichází jako řetězce, peníze v mikrojednotkách (÷ 1 000 000).

## Integrace do Nette projektu (neon)

```neon
services:
    googleAdsConfig: Ages\GoogleGateway\Config\GoogleAdsConfig(
        clientId: '…'
        clientSecret: '…'
        refreshToken: '…'
        customerId: '123-456-7890'
        # loginCustomerId: '111-222-3333'   # jen při přístupu přes manažerský účet (MCC)
        # apiVersion: v26                   # výchozí hodnota
    )
    - Ages\GoogleGateway\GoogleGateway(@googleAdsConfig)
```

> Klient se sestaví líně až při prvním volání — chybějící údaje (`GoogleException`) se projeví až tehdy,
> ne při startu aplikace. Volitelný druhý argument je **PSR-16** cache pro sdílení access tokenu.

## Verze API

Google vydává novou verzi zhruba čtyřikrát ročně a starší vypíná přibližně po roce. Výchozí je `v26`
(`GoogleAdsConfig::ApiVersion`); při vypnutí verze stačí zvednout `apiVersion` v konfiguraci, dotazy
v balíčku používají jen stabilní pole.

## Chyby

- `Ages\GoogleGateway\Exception\GoogleApiException` — nesprávný HTTP status API nebo OAuth
  (`->statusCode`, `->status` např. `PERMISSION_DENIED`, `->requestId` pro podporu Googlu, `->responseBody`)
- `Ages\GoogleGateway\Exception\GoogleException` — chybějící konfigurace, síťová / parsovací chyba (základní typ)

Časté příčiny:
- `AUTHORIZATION_ERROR` / přístup jen k testovacím účtům — projekt OAuth klienta má úroveň *Test*; požádej o *Explorer* (Google Ads API › Overview)
- `USER_PERMISSION_DENIED` — Google účet z refresh tokenu nemá přístup k účtu, nebo chybí `loginCustomerId` u přístupu přes MCC
- `invalid_grant` — refresh token vypršel (aplikace v režimu *Testing* = 7 dní) nebo byl odvolán

## Architektura

```
src/
├── Ads/
│   ├── AdsApi.php                    GAQL dotazy – náklady, rozpad, kampaně, účet, rozpočty
│   └── Response/
│       ├── Metrics.php               zobrazení, prokliky, útrata, konverze + CPC, CTR, PNO, ROAS
│       ├── CostRow.php               období + Metrics
│       ├── CampaignCost.php          kampaň + Metrics
│       ├── Customer.php              účet (měna, časová zóna)
│       └── AccountBudget.php         rozpočet účtu (měsíční fakturace)
├── Auth/
│   ├── AccessToken.php
│   └── OAuth.php                     jednorázové získání refresh tokenu
├── Config/
│   └── GoogleAdsConfig.php
├── Enum/
│   └── Granularity.php               Daily / Weekly / Monthly / Quarterly / Yearly
├── Exception/
│   ├── GoogleException.php
│   └── GoogleApiException.php
├── Http/
│   └── GoogleAdsClient.php           OAuth token, hlavičky, searchStream, opakování
└── GoogleGateway.php                 vstupní bod – ads(), getCosts(), getCostsBreakdown(), getCampaignCosts()
```
