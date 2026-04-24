<?php
declare(strict_types=1);

session_start();

if (!function_exists('str_contains')) {
	function str_contains(string $haystack, string $needle): bool
	{
		return $needle === '' || strpos($haystack, $needle) !== false;
	}
}

if (!function_exists('str_starts_with')) {
	function str_starts_with(string $haystack, string $needle): bool
	{
		if ($needle === '') {
			return true;
		}

		return substr($haystack, 0, strlen($needle)) === $needle;
	}
}

if (!function_exists('str_ends_with')) {
	function str_ends_with(string $haystack, string $needle): bool
	{
		if ($needle === '') {
			return true;
		}

		$needleLength = strlen($needle);
		if ($needleLength > strlen($haystack)) {
			return false;
		}

		return substr($haystack, -$needleLength) === $needle;
	}
}

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
	if ((error_reporting() & $severity) === 0) {
		return false;
	}

	throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(static function (Throwable $e): void {
	if (!headers_sent()) {
		header('Content-Type: text/html; charset=UTF-8');
		http_response_code(200);
	}

	echo '<h1>Error PHP</h1>';
	echo '<p><strong>Tipo:</strong> ' . htmlspecialchars(get_class($e), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
	echo '<p><strong>Mensaje:</strong> ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
	echo '<p><strong>Archivo:</strong> ' . htmlspecialchars($e->getFile(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
	echo '<p><strong>Linea:</strong> ' . (int) $e->getLine() . '</p>';
	echo '<pre style="white-space:pre-wrap;background:#f6f8fa;border:1px solid #d0d7de;border-radius:8px;padding:10px;">'
		. htmlspecialchars($e->getTraceAsString(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
		. '</pre>';
	exit;
});

register_shutdown_function(static function (): void {
	$lastError = error_get_last();
	if (!is_array($lastError)) {
		return;
	}

	$fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
	if (!in_array((int) ($lastError['type'] ?? 0), $fatalTypes, true)) {
		return;
	}

	if (!headers_sent()) {
		header('Content-Type: text/html; charset=UTF-8');
		http_response_code(200);
	}

	$message = (string) ($lastError['message'] ?? 'Fatal error');
	$file = (string) ($lastError['file'] ?? 'desconocido');
	$line = (int) ($lastError['line'] ?? 0);

	echo '<h1>Error fatal PHP</h1>';
	echo '<p><strong>Mensaje:</strong> ' . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
	echo '<p><strong>Archivo:</strong> ' . htmlspecialchars($file, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
	echo '<p><strong>Linea:</strong> ' . $line . '</p>';
});

function loadDotEnv(string $envPath): array
{
	if (!is_file($envPath) || !is_readable($envPath)) {
		return [];
	}

	$lines = file($envPath, FILE_IGNORE_NEW_LINES);
	if ($lines === false) {
		return [];
	}

	$env = [];
	foreach ($lines as $line) {
		$line = trim((string) $line);
		if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) {
			continue;
		}

		if (str_starts_with($line, 'export ')) {
			$line = trim(substr($line, 7));
		}

		$separatorPos = strpos($line, '=');
		if ($separatorPos === false) {
			continue;
		}

		$key = trim(substr($line, 0, $separatorPos));
		if ($key === '') {
			continue;
		}

		$value = trim(substr($line, $separatorPos + 1));
		$hasDoubleQuotes = str_starts_with($value, '"') && str_ends_with($value, '"');
		$hasSingleQuotes = str_starts_with($value, "'") && str_ends_with($value, "'");
		if ($hasDoubleQuotes || $hasSingleQuotes) {
			$value = substr($value, 1, -1);
			if ($hasDoubleQuotes) {
				$value = stripcslashes($value);
			}
		}

		$env[$key] = $value;
	}

	return $env;
}

function envToBool(?string $value, bool $default = false): bool
{
	if ($value === null) {
		return $default;
	}

	$normalized = strtolower(trim($value));
	if ($normalized === '') {
		return $default;
	}

	if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
		return true;
	}

	if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
		return false;
	}

	return $default;
}

$envPath = __DIR__ . DIRECTORY_SEPARATOR . '.env';
$env = loadDotEnv($envPath);

$scriptFilePath = (string) ($_SERVER['SCRIPT_FILENAME'] ?? __FILE__);
$serverFromFile = strtolower(trim((string) pathinfo($scriptFilePath, PATHINFO_FILENAME)));
$serverFromFile = preg_replace('/[^a-z0-9-]/', '', $serverFromFile);

if($serverFromFile === '' || $serverFromFile === 'index') {
	$serverFromFile = 'vara';
}

$server = $serverFromFile;

$serverBaseUrl = 'https://' . $server . '.e-sim.org/';

function serverUrl(string $path = ''): string
{
	global $serverBaseUrl;
	global $server;

	$base = is_string($serverBaseUrl) && trim($serverBaseUrl) !== ''
		? rtrim($serverBaseUrl, '/') . '/'
		: ('https://'. $server . '.e-sim.org/');

	if (trim($path) === '') {
		return $base;
	}

	return $base . ltrim($path, '/');
}

$baseUrl = serverUrl('index.html');
$username = (string) ($env[strtoupper($server) . '_ESIM_USERNAME'] ?? '');
$password = (string) ($env[strtoupper($server) . '_ESIM_PASSWORD'] ?? '');
$userId = (string) ($env[strtoupper($server) . '_ESIM_USER_ID'] ?? '');
$allowAutoRegistration = envToBool($env['ESIM_ALLOW_AUTO_REGISTRATION'] ?? null, false);

$cookieDir = __DIR__ . DIRECTORY_SEPARATOR . 'tmp';
if (!is_dir($cookieDir)) {
	$created = @mkdir($cookieDir, 0777, true);
	if ($created !== true && !is_dir($cookieDir)) {
		header('Content-Type: text/html; charset=UTF-8');
		http_response_code(200);
		echo '<h1>Error de configuracion</h1>';
		echo '<p>No se pudo crear la carpeta <strong>simulador/tmp</strong>. Revisa permisos de escritura del servidor web.</p>';
		exit;
	}
}

$cookieSuffix = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower($username));
if ($cookieSuffix === '') {
	$cookieSuffix = 'default';
}

$defaultCookieFile = $cookieDir . DIRECTORY_SEPARATOR . $server . '_cookie_' . $cookieSuffix . '.txt';
$cookieFile = $defaultCookieFile;
$_SESSION['curl_cookie_file'] = $cookieFile;

if (!function_exists('curl_init')) {
	header('Content-Type: text/html; charset=UTF-8');
	http_response_code(200);
	echo '<h1>Error de configuracion</h1>';
	echo '<p>La extension <strong>cURL</strong> no esta habilitada en PHP para este servidor.</p>';
	exit;
}

$ch = curl_init();
if ($ch === false) {
	header('Content-Type: text/html; charset=UTF-8');
	http_response_code(200);
	echo '<h1>Error de inicializacion</h1>';
	echo '<p>No se pudo inicializar cURL. Reinicia PHP/Apache y revisa error logs del servidor.</p>';
	exit;
}

$headers = [
	'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
	'Accept-Language: es-ES,es;q=0.9,en-US;q=0.8,en;q=0.7',
	'Cache-Control: no-cache',
	'Pragma: no-cache',
	'Upgrade-Insecure-Requests: 1',
];

$curlOptsOk = curl_setopt_array($ch, [
	CURLOPT_RETURNTRANSFER => true,
	CURLOPT_FOLLOWLOCATION => true,
	CURLOPT_MAXREDIRS => 10,
	CURLOPT_CONNECTTIMEOUT => 15,
	CURLOPT_TIMEOUT => 40,
	CURLOPT_ENCODING => '',
	CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2TLS,
	CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
	CURLOPT_HTTPHEADER => $headers,
	CURLOPT_SSL_VERIFYPEER => true,
	CURLOPT_SSL_VERIFYHOST => 2,
	CURLOPT_COOKIEJAR => $cookieFile,
	CURLOPT_COOKIEFILE => $cookieFile,
]);
if ($curlOptsOk !== true) {
	header('Content-Type: text/html; charset=UTF-8');
	http_response_code(200);
	echo '<h1>Error de configuracion cURL</h1>';
	echo '<p>No se pudo configurar cURL para esta sesion. Revisa permisos de cookie y ajustes de SSL/PHP.</p>';
	exit;
}

$step1 = curlRequest($ch, $baseUrl);
$fatalError = '';
$loginAttempted = false;
$sessionReused = false;
$registrationAttempted = false;
$registrationResult = [
	'attempted' => false,
	'saved' => false,
	'reason' => 'not-attempted',
	'country' => '26',
	'actionUrl' => '',
	'httpStatus' => 0,
];
$loggedUserValidation = [
	'checked' => false,
	'expected' => $username,
	'actual' => '',
	'matched' => false,
	'reason' => 'not-checked',
];
$trainRequested = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
	&& (string) ($_POST['action'] ?? '') === 'train-now';
$workRequested = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
	&& (string) ($_POST['action'] ?? '') === 'work-now';
$eatRequested = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
	&& (string) ($_POST['action'] ?? '') === 'eat-now';
$drinkRequested = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
	&& (string) ($_POST['action'] ?? '') === 'drink-now';
$leaveJobRequested = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
	&& (string) ($_POST['action'] ?? '') === 'leave-job-now';
$travelRequested = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
	&& (string) ($_POST['action'] ?? '') === 'travel-now';
$companyOffersLoadRequested = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
	&& (string) ($_POST['action'] ?? '') === 'company-offers-load';
$companyOfferApplyRequested = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
	&& (string) ($_POST['action'] ?? '') === 'company-offer-apply';
$regionTravelLoadRequested = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
	&& (string) ($_POST['action'] ?? '') === 'travel-region-load';
$travelCountryLoadRequested = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
	&& (string) ($_POST['action'] ?? '') === 'travel-country-load';
$regionsCatalogAnalyzeCountryRequested = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
	&& (string) ($_POST['action'] ?? '') === 'regions-catalog-analyze-country';
$notificationsRequested = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
	&& (string) ($_POST['action'] ?? '') === 'notifications-load';
$dailiesLoadRequested = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
	&& (string) ($_POST['action'] ?? '') === 'dailies-load';
$dailiesClaimRequested = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
	&& (string) ($_POST['action'] ?? '') === 'dailies-claim';
$changeEmailRequested = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
	&& (string) ($_POST['action'] ?? '') === 'change-email';
$resendConfirmationMailRequested = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
	&& (string) ($_POST['action'] ?? '') === 'resend-confirmation-mail';
$confirmMailCodeRequested = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
	&& (string) ($_POST['action'] ?? '') === 'confirm-mail-code';
$partyStatusCheckRequested = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
	&& (string) ($_POST['action'] ?? '') === 'party-status-check';
$partyInspectRequested = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
	&& (string) ($_POST['action'] ?? '') === 'party-inspect-url';
$partyJoinRequested = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
	&& (string) ($_POST['action'] ?? '') === 'party-join-now';
$partyLeaveRequested = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
	&& (string) ($_POST['action'] ?? '') === 'party-leave-now';
$productMarketRequested = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
	&& (string) ($_POST['action'] ?? '') === 'product-market-load';
$productMarketOffersRequested = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
	&& (string) ($_POST['action'] ?? '') === 'product-market-offers-load';
$productMarketBuyRequested = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
	&& (string) ($_POST['action'] ?? '') === 'product-market-offer-buy';
$banditBlueOpenRequested = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
	&& (string) ($_POST['action'] ?? '') === 'bandit-blue-open';
$banditBlueRunRequested = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
	&& (string) ($_POST['action'] ?? '') === 'bandit-blue-run';
$logoutRequested = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
	&& (string) ($_POST['action'] ?? '') === 'logout-now';
$tutorialMissionCompleteRequested = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
	&& (string) ($_POST['action'] ?? '') === 'tutorial-mission-complete';
$tutorialMissionSkipRequested = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
	&& (string) ($_POST['action'] ?? '') === 'tutorial-mission-skip';
$freeStarterPackOpenRequested = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
	&& (string) ($_POST['action'] ?? '') === 'free-starter-pack-open';
$equipmentLoadRequested = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
	&& (string) ($_POST['action'] ?? '') === 'equipment-load';
$equipmentSellRequested = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
	&& (string) ($_POST['action'] ?? '') === 'equipment-sell-now';
$auctionMarketLoadRequested = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
	&& (string) ($_POST['action'] ?? '') === 'auctions-load';
$auctionBidRequested = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
	&& (string) ($_POST['action'] ?? '') === 'auction-bid-now';
$articleInspectRequested = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
	&& (string) ($_POST['action'] ?? '') === 'article-inspect-url';
$articleVoteRequested = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
	&& (string) ($_POST['action'] ?? '') === 'article-vote-now';
$articleSubscribeRequested = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
	&& (string) ($_POST['action'] ?? '') === 'article-subscribe-now';
$electionsInspectRequested = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
	&& (string) ($_POST['action'] ?? '') === 'elections-inspect-url';
$electionsCandidateRequested = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
	&& (string) ($_POST['action'] ?? '') === 'elections-congress-candidate-now';
$militaryUnitInspectRequested = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
	&& (string) ($_POST['action'] ?? '') === 'military-unit-inspect-url';
$militaryUnitApplyRequested = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
	&& (string) ($_POST['action'] ?? '') === 'military-unit-apply-now';
$freeStarterPackClaimRequested = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
	&& (string) ($_POST['action'] ?? '') === 'free-starter-pack-claim';
$trainAttempted = false;
$trainResult = [
	'attempted' => false,
	'saved' => false,
	'reason' => 'not-attempted',
	'actionUrl' => '',
	'httpStatus' => 0,
];
$workAttempted = false;
$workResult = [
	'attempted' => false,
	'saved' => false,
	'reason' => 'not-attempted',
	'actionUrl' => '',
	'httpStatus' => 0,
];
$eatAttempted = false;
$eatResult = [
	'attempted' => false,
	'saved' => false,
	'reason' => 'not-attempted',
	'actionUrl' => '',
	'httpStatus' => 0,
	'energy' => '',
];
$drinkAttempted = false;
$drinkResult = [
	'attempted' => false,
	'saved' => false,
	'reason' => 'not-attempted',
	'actionUrl' => '',
	'httpStatus' => 0,
	'energy' => '',
];
$leaveJobAttempted = false;
$leaveJobResult = [
	'attempted' => false,
	'saved' => false,
	'reason' => 'not-attempted',
	'actionUrl' => '',
	'httpStatus' => 0,
];
$travelAttempted = false;
$travelResult = [
	'attempted' => false,
	'saved' => false,
	'reason' => 'not-attempted',
	'actionUrl' => '',
	'httpStatus' => 0,
	'destination' => '',
	'ticketQuality' => '',
];
$battleActionType = (string) ($_POST['action'] ?? '');
$battleActionRequested = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
	&& in_array($battleActionType, ['battle-fight', 'battle-change-side', 'battle-fight-request'], true);
$battleInspectRequested = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
	&& $battleActionType === 'battle-inspect';
$isAsyncActionRequest = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
	&& (
		(string) ($_POST['async'] ?? '') === '1'
		|| strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
	);
$battleActionAttempted = false;
$battleActionResult = [
	'attempted' => false,
	'saved' => false,
	'reason' => 'not-attempted',
	'type' => '',
	'battleTitle' => '',
	'actionUrl' => '',
	'requestPayload' => [],
	'requestPayloadEncoded' => '',
	'httpStatus' => 0,
	'energy' => '',
	'damage' => '',
];
$battleInspectResult = [
	'attempted' => false,
	'saved' => false,
	'reason' => 'not-attempted',
	'httpStatus' => 0,
	'battleUrl' => '',
	'battleTitle' => '',
];
$battleDetailsCache = [];
if (isset($_SESSION['battle_details_cache']) && is_array($_SESSION['battle_details_cache'])) {
	$battleDetailsCache = $_SESSION['battle_details_cache'];
}
$battlesUrl = serverUrl('battles.html?countryId=-1&page=1');
$battlesResult = [
	'attempted' => false,
	'saved' => false,
	'reason' => 'not-attempted',
	'httpStatus' => 0,
	'url' => $battlesUrl,
	'items' => [],
	'bodyLength' => 0,
	'pagesScanned' => 0,
	'practiceFound' => false,
];
$workplaceUrl = serverUrl('work2.html');
$workplaceResult = [
	'attempted' => false,
	'saved' => false,
	'reason' => 'not-attempted',
	'httpStatus' => 0,
	'url' => $workplaceUrl,
	'companyName' => '',
	'companyUrl' => '',
	'companyOwner' => '',
	'companyOwnerType' => '',
	'companyOwnerUrl' => '',
	'canWork' => false,
	'canLeave' => false,
	'leaveActionUrl' => '',
	'leaveFields' => [],
];
$companyOffersResult = [
	'attempted' => false,
	'saved' => false,
	'reason' => 'not-attempted',
	'httpStatus' => 0,
	'sourceUrl' => '',
	'companyName' => '',
	'offers' => [],
	'error' => '',
];
$companyOfferApplyResult = [
	'attempted' => false,
	'saved' => false,
	'reason' => 'not-attempted',
	'actionUrl' => '',
	'httpStatus' => 0,
	'offerId' => '',
	'error' => '',
];
$regionTravelLookupResult = [
	'attempted' => false,
	'saved' => false,
	'reason' => 'not-attempted',
	'httpStatus' => 0,
	'regionUrl' => '',
	'regionId' => '',
	'regionName' => '',
	'currentOwner' => '',
	'rightfulOwner' => '',
	'resource' => '',
	'travelForm' => emptyTravelFormData(),
	'error' => '',
];
$travelCountryListResult = [
	'attempted' => false,
	'saved' => false,
	'reason' => 'not-attempted',
	'httpStatus' => 0,
	'sourceUrl' => '',
	'countries' => [],
	'error' => '',
];
$travelCountryRegionsResult = [
	'attempted' => false,
	'saved' => false,
	'reason' => 'not-attempted',
	'httpStatus' => 0,
	'sourceUrl' => '',
	'countryId' => '',
	'regions' => [],
	'error' => '',
];
$regionsCatalogManualPath = __DIR__ . DIRECTORY_SEPARATOR . 'regions_catalog_manual.json';
$regionsCatalogManualResult = [
	'attempted' => false,
	'saved' => false,
	'reason' => 'not-attempted',
	'countryId' => '',
	'countryName' => '',
	'regionsProcessed' => 0,
	'catalogPath' => $regionsCatalogManualPath,
	'error' => '',
];
$regionsCatalogManualStatus = [
	'exists' => false,
	'countryCount' => 0,
	'regionCount' => 0,
	'path' => $regionsCatalogManualPath,
];
$notificationsUrl = serverUrl('notifications.html');
$notificationsResult = [
	'attempted' => false,
	'saved' => false,
	'reason' => 'not-attempted',
	'httpStatus' => 0,
	'url' => $notificationsUrl,
	'bodyLength' => 0,
	'items' => [],
	'itemsCount' => 0,
	'error' => '',
];
$dailiesUrl = serverUrl('missionCenter/dailies');
$dailiesResult = [
	'attempted' => false,
	'saved' => false,
	'reason' => 'not-attempted',
	'httpStatus' => 0,
	'url' => $dailiesUrl,
	'bodyLength' => 0,
	'items' => [],
	'itemsCount' => 0,
	'claimableCount' => 0,
	'error' => '',
];
$dailiesClaimResult = [
	'attempted' => false,
	'saved' => false,
	'reason' => 'not-attempted',
	'httpStatus' => 0,
	'url' => '',
	'claimUrl' => '',
	'dailyId' => '',
	'responseSnippet' => '',
	'error' => '',
];
$changeEmailResult = [
	'attempted' => false,
	'saved' => false,
	'reason' => 'not-attempted',
	'httpStatus' => 0,
	'url' => serverUrl('editCitizen.html'),
	'email' => '',
	'responseSnippet' => '',
	'error' => '',
];
$registeredEmailResult = [
	'attempted' => false,
	'saved' => false,
	'reason' => 'not-attempted',
	'httpStatus' => 0,
	'url' => serverUrl('editCitizen.html?editCitizenPage=PERSONAL_DATA'),
	'email' => '',
	'error' => '',
];
$resendConfirmationMailResult = [
	'attempted' => false,
	'saved' => false,
	'reason' => 'not-attempted',
	'httpStatus' => 0,
	'url' => serverUrl('resendConfirmationMail.html'),
	'responseSnippet' => '',
	'error' => '',
];
$confirmMailCodeResult = [
	'attempted' => false,
	'saved' => false,
	'reason' => 'not-attempted',
	'httpStatus' => 0,
	'url' => '',
	'citizenId' => '',
	'stamp' => '',
	'responseSnippet' => '',
	'error' => '',
];
$partyStatusCheckResult = [
	'attempted' => false,
	'saved' => false,
	'reason' => 'not-attempted',
	'httpStatus' => 0,
	'url' => serverUrl('myParty.html'),
	'needsEmailConfirmation' => false,
	'responseSnippet' => '',
	'error' => '',
];
$partyInspectResult = [
	'attempted' => false,
	'saved' => false,
	'reason' => 'not-attempted',
	'httpStatus' => 0,
	'url' => '',
	'partyName' => '',
	'joinDetected' => false,
	'hasJoinForm' => false,
	'hasJoinButton' => false,
	'joinActionUrl' => '',
	'joinMethod' => '',
	'joinFields' => [],
	'joinIndicator' => '',
	'leaveDetected' => false,
	'hasLeaveForm' => false,
	'leaveActionUrl' => '',
	'leaveMethod' => '',
	'leaveFields' => [],
	'responseSnippet' => '',
	'responseHtml' => '',
	'error' => '',
];
$partyJoinResult = [
	'attempted' => false,
	'saved' => false,
	'reason' => 'not-attempted',
	'httpStatus' => 0,
	'url' => '',
	'partyName' => '',
	'joinActionUrl' => '',
	'joinMethod' => '',
	'joinReferer' => '',
	'joinChoice' => 'no',
	'curlErrno' => 0,
	'totalTime' => 0.0,
	'requestPayload' => '',
	'responseSnippet' => '',
	'responseHtml' => '',
	'error' => '',
];
$partyLeaveResult = [
	'attempted' => false,
	'saved' => false,
	'reason' => 'not-attempted',
	'httpStatus' => 0,
	'url' => '',
	'partyName' => '',
	'leaveActionUrl' => '',
	'leaveMethod' => 'POST',
	'requestPayload' => '',
	'responseSnippet' => '',
	'responseHtml' => '',
	'error' => '',
];
$storageMoneyUrl = serverUrl('storage.html?storageType=MONEY');
$storageMoneyResult = [
	'attempted' => false,
	'saved' => false,
	'reason' => 'not-attempted',
	'httpStatus' => 0,
	'url' => $storageMoneyUrl,
	'bodyLength' => 0,
	'accounts' => [],
	'accountsCount' => 0,
	'error' => '',
];
$storageEquipmentUrl = serverUrl('storage.html?storageType=EQUIPMENT');
$storageEquipmentListUrl = serverUrl('storage/equipmentList');
$storageEquipmentResult = [
	'attempted' => false,
	'saved' => false,
	'reason' => 'not-attempted',
	'httpStatus' => 0,
	'url' => $storageEquipmentUrl,
	'inventoryUrl' => $storageEquipmentListUrl,
	'inventoryHttpStatus' => 0,
	'bodyLength' => 0,
	'equipped' => [],
	'storage' => [],
	'equippedCount' => 0,
	'storageCount' => 0,
	'error' => '',
];
$equipmentSellResult = [
	'attempted' => false,
	'saved' => false,
	'reason' => 'not-attempted',
	'httpStatus' => 0,
	'url' => '',
	'itemId' => '',
	'price' => '',
	'length' => '',
	'responseSnippet' => '',
	'error' => '',
];
$freeStarterPackResult = [
	'checked' => false,
	'found' => false,
	'claimButtonFound' => false,
	'source' => '',
	'openUrl' => serverUrl('shop.html?shopType=PROMOTIONS'),
	'claimUrl' => '',
	'reason' => 'not-checked',
];
$freeStarterPackClaimResult = [
	'attempted' => false,
	'saved' => false,
	'reason' => 'not-attempted',
	'httpStatus' => 0,
	'url' => '',
	'claimUrl' => '',
	'responseSnippet' => '',
	'error' => '',
];
$freeStarterPackOpenResult = [
	'attempted' => false,
	'saved' => false,
	'reason' => 'not-attempted',
	'httpStatus' => 0,
	'url' => '',
	'bodyLength' => 0,
	'found' => false,
	'claimButtonFound' => false,
	'claimUrl' => '',
	'error' => '',
];
$tutorialMissionState = [
	'checked' => false,
	'hasTutorialBallContainer' => false,
	'hasMissionDropdown' => false,
	'hasInProgressPanel' => false,
	'inProgressTitle' => '',
	'inProgressDescription' => '',
	'selectedMissionTitle' => '',
	'selectedMissionDescription' => '',
	'inProgressSummary' => '',
	'hasRewardMissionForm' => false,
	'rewardActionUrl' => serverUrl('betaMissions.html'),
	'rewardMethod' => 'POST',
	'rewardFields' => [],
	'hasSkipOption' => false,
	'skipActionUrl' => '',
	'skipMethod' => 'POST',
	'skipFields' => [],
	'availableMissionCount' => 0,
	'reason' => 'not-checked',
];
$auctionsUrl = serverUrl('auctions.html');
$auctionMarketResult = [
	'attempted' => false,
	'saved' => false,
	'reason' => 'not-attempted',
	'httpStatus' => 0,
	'url' => $auctionsUrl,
	'bodyLength' => 0,
	'itemsCount' => 0,
	'offers' => [],
	'error' => '',
];
$auctionBidResult = [
	'attempted' => false,
	'saved' => false,
	'reason' => 'not-attempted',
	'httpStatus' => 0,
	'url' => '',
	'auctionId' => '',
	'price' => '',
	'responseSnippet' => '',
	'error' => '',
];
$articleInspectResult = [
	'attempted' => false,
	'saved' => false,
	'reason' => 'not-attempted',
	'httpStatus' => 0,
	'url' => '',
	'articleId' => '',
	'articleTitle' => '',
	'voteDetected' => false,
	'subscribeDetected' => false,
	'voteActionUrl' => '',
	'subscribeActionUrl' => '',
	'responseSnippet' => '',
	'error' => '',
];
$articleVoteResult = [
	'attempted' => false,
	'saved' => false,
	'reason' => 'not-attempted',
	'httpStatus' => 0,
	'url' => '',
	'articleId' => '',
	'responseSnippet' => '',
	'error' => '',
];
$articleSubscribeResult = [
	'attempted' => false,
	'saved' => false,
	'reason' => 'not-attempted',
	'httpStatus' => 0,
	'url' => '',
	'articleId' => '',
	'responseSnippet' => '',
	'error' => '',
];
$electionsInspectResult = [
	'attempted' => false,
	'saved' => false,
	'reason' => 'not-attempted',
	'httpStatus' => 0,
	'url' => serverUrl('elections.html?electionType=CONGRESS'),
	'pageTitle' => '',
	'candidateActionUrl' => '',
	'options' => [],
	'responseSnippet' => '',
	'responseHtml' => '',
	'error' => '',
];
$electionsCandidateResult = [
	'attempted' => false,
	'saved' => false,
	'reason' => 'not-attempted',
	'httpStatus' => 0,
	'url' => '',
	'presentation' => '',
	'requestPayload' => '',
	'responseSnippet' => '',
	'responseHtml' => '',
	'error' => '',
];
$militaryUnitInspectResult = [
	'attempted' => false,
	'saved' => false,
	'reason' => 'not-attempted',
	'httpStatus' => 0,
	'url' => serverUrl('militaryUnit.html?id=37'),
	'unitName' => '',
	'applyDetected' => false,
	'applyActionUrl' => '',
	'applyMethod' => 'POST',
	'applyFields' => [],
	'applyDefaultMessage' => '',
	'options' => [],
	'responseSnippet' => '',
	'responseHtml' => '',
	'error' => '',
];
$militaryUnitApplyResult = [
	'attempted' => false,
	'saved' => false,
	'reason' => 'not-attempted',
	'httpStatus' => 0,
	'url' => '',
	'unitId' => '',
	'message' => '',
	'requestPayload' => '',
	'responseSnippet' => '',
	'responseHtml' => '',
	'error' => '',
];
$tutorialMissionCompleteResult = [
	'attempted' => false,
	'saved' => false,
	'reason' => 'not-attempted',
	'url' => serverUrl('betaMissions.html'),
	'method' => 'POST',
	'firstHttpStatus' => 0,
	'secondHttpStatus' => 0,
	'firstSnippet' => '',
	'secondSnippet' => '',
	'error' => '',
];
$tutorialMissionSkipResult = [
	'attempted' => false,
	'saved' => false,
	'reason' => 'not-attempted',
	'url' => serverUrl('betaMissions.html'),
	'method' => 'POST',
	'httpStatus' => 0,
	'responseSnippet' => '',
	'error' => '',
];
$productMarketUrl = serverUrl('productMarket.html');
$productMarketOffersBaseUrl = serverUrl('productMarketOffers');
$productMarketResult = [
	'attempted' => false,
	'saved' => false,
	'reason' => 'not-attempted',
	'httpStatus' => 0,
	'url' => $productMarketUrl,
	'bodyLength' => 0,
	'error' => '',
];
$productMarketOffersResult = [
	'attempted' => false,
	'saved' => false,
	'reason' => 'not-attempted',
	'httpStatus' => 0,
	'url' => '',
	'bodyLength' => 0,
	'type' => '',
	'quality' => '',
	'countryId' => '-1',
	'page' => '',
	'offers' => [],
	'itemsCount' => 0,
	'error' => '',
];
$productMarketBuyResult = [
	'attempted' => false,
	'saved' => false,
	'reason' => 'not-attempted',
	'httpStatus' => 0,
	'url' => '',
	'offerId' => '',
	'quantity' => '',
	'currencyId' => '',
	'responseSnippet' => '',
	'requestPayload' => [],
	'error' => '',
];
$gameRoomUrl = serverUrl('gameRoom.html');
$banditBlueOpenResult = [
	'attempted' => false,
	'saved' => false,
	'reason' => 'not-attempted',
	'gameRoomHttpStatus' => 0,
	'banditHttpStatus' => 0,
	'url' => '',
	'containsHandlePlay' => false,
	'error' => '',
];
$banditBlueRunResult = [
	'attempted' => false,
	'saved' => false,
	'reason' => 'not-attempted',
	'playHttpStatus' => 0,
	'rewardHttpStatus' => 0,
	'url' => '',
	'rewardUrl' => '',
	'runId' => '',
	'rewardSnippet' => '',
	'error' => '',
];
$logoutResult = [
	'attempted' => false,
	'saved' => false,
	'reason' => 'not-attempted',
	'url' => '',
	'httpStatus' => 0,
	'localCookiesDeleted' => 0,
	'error' => '',
];
$registrationAutoBlocked = false;

if ($step1['errno'] !== 0) {
	$fatalError = 'GET inicial fallo: ' . $step1['error'];
}

$alreadyAuthenticated = $fatalError === ''
	? looksAuthenticated((string) $step1['body'])
	: false;

if ($alreadyAuthenticated) {
	$sessionReused = true;
}

$loginForm = ($fatalError === '' && !$sessionReused)
	? extractLoginForm((string) $step1['body'], (string) $step1['effectiveUrl'])
	: ['found' => false, 'actionUrl' => '', 'method' => 'POST', 'fields' => []];

$step2 = [
	'url' => '',
	'statusCode' => 0,
	'effectiveUrl' => '',
	'contentType' => '',
	'totalTime' => 0.0,
	'errno' => 0,
	'error' => '',
	'body' => '',
];

if ($fatalError === '' && !$sessionReused && $loginForm['found']) {
	$loginAttempted = true;
	$postFields = is_array($loginForm['fields']) ? $loginForm['fields'] : [];
	$postFields['login'] = $username;
	$postFields['password'] = $password;

	$postHeaders = [
		'Content-Type: application/x-www-form-urlencoded',
		'Origin: ' . rtrim(serverUrl(''), '/'),
		'Referer: ' . (string) $step1['effectiveUrl'],
	];

	$step2 = curlRequest($ch, (string) $loginForm['actionUrl'], [
		CURLOPT_POST => true,
		CURLOPT_POSTFIELDS => http_build_query($postFields),
		CURLOPT_HTTPHEADER => array_merge($headers, $postHeaders),
	]);

	if ($step2['errno'] !== 0) {
		$fatalError = 'POST de login fallo: ' . $step2['error'];
	}
}

$step3 = [
	'url' => '',
	'statusCode' => 0,
	'effectiveUrl' => '',
	'contentType' => '',
	'totalTime' => 0.0,
	'errno' => 0,
	'error' => '',
	'body' => '',
];

if ($fatalError === '') {
	if ($sessionReused) {
		$step3 = $step1;
	} else {
		$step3 = curlRequest($ch, $baseUrl, [
			CURLOPT_POST => false,
			CURLOPT_HTTPGET => true,
			CURLOPT_HTTPHEADER => $headers,
		]);
	}

	if ($step3['errno'] !== 0) {
		$fatalError = 'GET autenticado fallo: ' . $step3['error'];
	}
}

if ($fatalError === '') {
	$loggedUserValidation['checked'] = true;
	if (looksAuthenticated((string) ($step3['body'] ?? ''))) {
		$previewInfo = extractLoggedPlayerInfo((string) ($step3['body'] ?? ''));
		$actualName = trim((string) ($previewInfo['name'] ?? ''));
		$loggedUserValidation['actual'] = $actualName;
		if ($actualName !== '' && !sameEsimUserName($actualName, $username)) {
			$loggedUserValidation['reason'] = 'user-mismatch-relogin-attempted';
			if (is_file($cookieFile)) {
				@file_put_contents($cookieFile, '');
			}

			$step1Retry = curlRequest($ch, $baseUrl, [
				CURLOPT_POST => false,
				CURLOPT_HTTPGET => true,
				CURLOPT_HTTPHEADER => $headers,
			]);
			if ($step1Retry['errno'] !== 0) {
				$fatalError = 'GET inicial reintento fallo: ' . $step1Retry['error'];
			} else {
				$loginFormRetry = extractLoginForm((string) $step1Retry['body'], (string) $step1Retry['effectiveUrl']);
				if (!empty($loginFormRetry['found'])) {
					$loginAttempted = true;
					$postFieldsRetry = is_array($loginFormRetry['fields'] ?? null) ? (array) $loginFormRetry['fields'] : [];
					$postFieldsRetry['login'] = $username;
					$postFieldsRetry['password'] = $password;
					$postHeadersRetry = [
						'Content-Type: application/x-www-form-urlencoded',
						'Origin: ' . rtrim(serverUrl(''), '/'),
						'Referer: ' . (string) $step1Retry['effectiveUrl'],
					];
					$step2Retry = curlRequest($ch, (string) ($loginFormRetry['actionUrl'] ?? ''), [
						CURLOPT_POST => true,
						CURLOPT_POSTFIELDS => http_build_query($postFieldsRetry),
						CURLOPT_HTTPHEADER => array_merge($headers, $postHeadersRetry),
					]);
					if ($step2Retry['errno'] !== 0) {
						$fatalError = 'POST de login reintento fallo: ' . $step2Retry['error'];
					}
				}

				if ($fatalError === '') {
					$step3 = curlRequest($ch, $baseUrl, [
						CURLOPT_POST => false,
						CURLOPT_HTTPGET => true,
						CURLOPT_HTTPHEADER => $headers,
					]);
					if ($step3['errno'] !== 0) {
						$fatalError = 'GET autenticado reintento fallo: ' . $step3['error'];
					}
				}
			}

			if ($fatalError === '') {
				$retryInfo = extractLoggedPlayerInfo((string) ($step3['body'] ?? ''));
				$retryActual = trim((string) ($retryInfo['name'] ?? ''));
				$loggedUserValidation['actual'] = $retryActual;
				$loggedUserValidation['matched'] = ($retryActual !== '') && sameEsimUserName($retryActual, $username);
				$loggedUserValidation['reason'] = $loggedUserValidation['matched']
					? 'user-matched-after-relogin'
					: 'user-mismatch-after-relogin';
			}
		} else {
			$loggedUserValidation['matched'] = true;
			$loggedUserValidation['reason'] = $actualName === '' ? 'authenticated-user-name-empty' : 'user-matched';
		}
	} else {
		$loggedUserValidation['reason'] = 'not-authenticated';
	}
}

if ($fatalError === '' && $logoutRequested) {
	$logoutResult = submitLogout($ch, (string) ($step3['effectiveUrl'] ?: $baseUrl), $headers);
	$logoutResult['localCookiesDeleted'] = clearAllLocalCookieFiles($cookieDir);

	unset($_SESSION['battle_details_cache'], $_SESSION['curl_last_login'], $_SESSION['curl_cookie_file']);
	$_SESSION['curl_cookie_file'] = $defaultCookieFile;

	$step3 = curlRequest($ch, $baseUrl, [
		CURLOPT_POST => false,
		CURLOPT_HTTPGET => true,
		CURLOPT_HTTPHEADER => $headers,
	]);

	if ($step3['errno'] !== 0) {
		$fatalError = 'GET post-logout fallo: ' . $step3['error'];
	}
}

if ($fatalError === '' && !$sessionReused && !looksAuthenticated((string) $step3['body']) && $allowAutoRegistration) {
	$registrationAttempted = true;
	$registrationForm = extractAdvancedRegistrationForm((string) $step1['body'], (string) $step1['effectiveUrl']);
	$registrationResult = [
		'attempted' => true,
		'saved' => false,
		'reason' => 'registration-form-not-found',
		'country' => '26',
		'actionUrl' => '',
		'httpStatus' => 0,
	];

	if ($registrationForm['found']) {
		$registrationFields = buildAdvancedRegistrationPayload($registrationForm, $username, $password, '26');
		$registrationPostHeaders = [
			'Content-Type: application/x-www-form-urlencoded',
			'Origin: ' . rtrim(serverUrl(''), '/'),
			'Referer: ' . (string) $step1['effectiveUrl'],
		];

		$registrationStep = curlRequest($ch, (string) $registrationForm['actionUrl'], [
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => http_build_query($registrationFields),
			CURLOPT_HTTPHEADER => array_merge($headers, $registrationPostHeaders),
		]);

		$registrationAccepted = $registrationStep['errno'] === 0
			&& (int) $registrationStep['statusCode'] >= 200
			&& (int) $registrationStep['statusCode'] < 400;
		$registrationResult = [
			'attempted' => true,
			'saved' => $registrationAccepted,
			'reason' => $registrationAccepted ? 'registration-submitted' : ($registrationStep['errno'] !== 0 ? 'registration-request-error' : 'registration-rejected'),
			'country' => '26',
			'actionUrl' => (string) $registrationForm['actionUrl'],
			'httpStatus' => (int) $registrationStep['statusCode'],
			'error' => (string) $registrationStep['error'],
		];

		$step3 = curlRequest($ch, $baseUrl, [
			CURLOPT_POST => false,
			CURLOPT_HTTPGET => true,
			CURLOPT_HTTPHEADER => $headers,
		]);

		if ($step3['errno'] !== 0) {
			$fatalError = 'GET post-registro fallo: ' . $step3['error'];
		}
	}
}

if ($fatalError === '' && !$sessionReused && !looksAuthenticated((string) $step3['body']) && !$allowAutoRegistration) {
	$registrationAutoBlocked = true;
	$registrationResult = [
		'attempted' => false,
		'saved' => false,
		'reason' => 'auto-registration-disabled',
		'country' => '26',
		'actionUrl' => '',
		'httpStatus' => 0,
	];
}

if ($fatalError === '' && $trainRequested) {
	$trainAttempted = true;
	$trainResult = [
		'attempted' => true,
		'saved' => false,
		'reason' => 'train-button-not-found',
		'actionUrl' => '',
		'httpStatus' => 0,
	];

	if (hasTaskTrainButton((string) $step3['body'])) {
		$trainStep = submitTrainTask($ch, (string) $step3['effectiveUrl'], $headers);
		$trainAccepted = $trainStep['errno'] === 0
			&& (int) $trainStep['statusCode'] >= 200
			&& (int) $trainStep['statusCode'] < 400;
		$trainResult = [
			'attempted' => true,
			'saved' => $trainAccepted,
			'reason' => $trainAccepted ? 'train-submitted' : ($trainStep['errno'] !== 0 ? 'train-request-error' : 'train-rejected'),
			'actionUrl' => (string) $trainStep['url'],
			'httpStatus' => (int) $trainStep['statusCode'],
			'error' => (string) $trainStep['error'],
		];

		$step3 = curlRequest($ch, $baseUrl, [
			CURLOPT_POST => false,
			CURLOPT_HTTPGET => true,
			CURLOPT_HTTPHEADER => $headers,
		]);

		if ($step3['errno'] !== 0) {
			$fatalError = 'GET post-train fallo: ' . $step3['error'];
		}
	}
}

if ($fatalError === '' && $workRequested) {
	$workAttempted = true;
	$workResult = [
		'attempted' => true,
		'saved' => false,
		'reason' => 'work-button-not-found',
		'actionUrl' => '',
		'httpStatus' => 0,
	];

	if (hasTaskWorkButton((string) $step3['body'])) {
		$workStep = submitWorkTask($ch, (string) $step3['effectiveUrl'], $headers, (string) $step3['body']);
		$workAccepted = $workStep['errno'] === 0
			&& (int) $workStep['statusCode'] >= 200
			&& (int) $workStep['statusCode'] < 400;
		$workResult = [
			'attempted' => true,
			'saved' => $workAccepted,
			'reason' => $workAccepted ? 'work-submitted' : ($workStep['errno'] !== 0 ? 'work-request-error' : 'work-rejected'),
			'actionUrl' => (string) $workStep['url'],
			'httpStatus' => (int) $workStep['statusCode'],
			'error' => (string) $workStep['error'],
		];

		$step3 = curlRequest($ch, $baseUrl, [
			CURLOPT_POST => false,
			CURLOPT_HTTPGET => true,
			CURLOPT_HTTPHEADER => $headers,
		]);

		if ($step3['errno'] !== 0) {
			$fatalError = 'GET post-work fallo: ' . $step3['error'];
		}
	}
}

if ($fatalError === '' && $eatRequested) {
	$eatAttempted = true;
	$eatQuality = (string) ($_POST['eat_quality'] ?? '5');
	if (!in_array($eatQuality, ['2', '5'], true)) {
		$eatQuality = '5';
	}

	$eatStep = submitConsumableTask($ch, (string) $step3['effectiveUrl'], $headers, 'Eat.html', $eatQuality);
	$eatAccepted = $eatStep['errno'] === 0
		&& (int) $eatStep['statusCode'] >= 200
		&& (int) $eatStep['statusCode'] < 400;
	$eatResult = [
		'attempted' => true,
		'saved' => $eatAccepted,
		'reason' => $eatAccepted ? 'eat-submitted' : ($eatStep['errno'] !== 0 ? 'eat-request-error' : 'eat-rejected'),
		'actionUrl' => (string) $eatStep['url'],
		'httpStatus' => (int) $eatStep['statusCode'],
		'error' => (string) $eatStep['error'],
		'energy' => extractEnergyFromAnyResponse((string) ($eatStep['body'] ?? '')),
	];

	$step3 = curlRequest($ch, $baseUrl, [
		CURLOPT_POST => false,
		CURLOPT_HTTPGET => true,
		CURLOPT_HTTPHEADER => $headers,
	]);

	if ($step3['errno'] !== 0) {
		$fatalError = 'GET post-eat fallo: ' . $step3['error'];
	} elseif ($eatResult['energy'] === '') {
		$eatResult['energy'] = extractEnergyFromAnyResponse((string) ($step3['body'] ?? ''));
	}
}

if ($fatalError === '' && $drinkRequested) {
	$drinkAttempted = true;
	$drinkQuality = (string) ($_POST['drink_quality'] ?? '5');
	if (!in_array($drinkQuality, ['2', '5'], true)) {
		$drinkQuality = '5';
	}

	$drinkStep = submitConsumableTask($ch, (string) $step3['effectiveUrl'], $headers, 'gift.html', $drinkQuality);
	$drinkAccepted = $drinkStep['errno'] === 0
		&& (int) $drinkStep['statusCode'] >= 200
		&& (int) $drinkStep['statusCode'] < 400;
	$drinkResult = [
		'attempted' => true,
		'saved' => $drinkAccepted,
		'reason' => $drinkAccepted ? 'drink-submitted' : ($drinkStep['errno'] !== 0 ? 'drink-request-error' : 'drink-rejected'),
		'actionUrl' => (string) $drinkStep['url'],
		'httpStatus' => (int) $drinkStep['statusCode'],
		'error' => (string) $drinkStep['error'],
		'energy' => extractEnergyFromAnyResponse((string) ($drinkStep['body'] ?? '')),
	];

	$step3 = curlRequest($ch, $baseUrl, [
		CURLOPT_POST => false,
		CURLOPT_HTTPGET => true,
		CURLOPT_HTTPHEADER => $headers,
	]);

	if ($step3['errno'] !== 0) {
		$fatalError = 'GET post-drink fallo: ' . $step3['error'];
	} elseif ($drinkResult['energy'] === '') {
		$drinkResult['energy'] = extractEnergyFromAnyResponse((string) ($step3['body'] ?? ''));
	}
}

if ($fatalError === '' && $leaveJobRequested) {
	$leaveJobAttempted = true;
	$leaveJobResult = [
		'attempted' => true,
		'saved' => false,
		'reason' => 'leave-form-not-found',
		'actionUrl' => '',
		'httpStatus' => 0,
	];

	$workplaceStep = curlRequest($ch, $workplaceUrl, [
		CURLOPT_POST => false,
		CURLOPT_HTTPGET => true,
		CURLOPT_HTTPHEADER => $headers,
	]);

	$workplaceHtml = (string) ($workplaceStep['body'] ?? '');
	$workplaceOk = $workplaceStep['errno'] === 0
		&& (int) $workplaceStep['statusCode'] >= 200
		&& (int) $workplaceStep['statusCode'] < 400
		&& trim($workplaceHtml) !== '';

	if ($workplaceOk) {
		$workInfoNow = extractWorkplaceInfo($workplaceHtml, (string) ($workplaceStep['effectiveUrl'] ?: $workplaceUrl));
		$leaveActionUrl = trim((string) ($workInfoNow['leaveActionUrl'] ?? ''));
		$leaveFields = is_array($workInfoNow['leaveFields'] ?? null) ? $workInfoNow['leaveFields'] : [];

		if ($leaveActionUrl !== '' && !empty($leaveFields)) {
			$leaveStep = submitLeaveJob($ch, (string) ($workplaceStep['effectiveUrl'] ?: $workplaceUrl), $headers, $leaveActionUrl, $leaveFields);
			$leaveAccepted = $leaveStep['errno'] === 0
				&& (int) $leaveStep['statusCode'] >= 200
				&& (int) $leaveStep['statusCode'] < 400;
			$leaveJobResult = [
				'attempted' => true,
				'saved' => $leaveAccepted,
				'reason' => $leaveAccepted ? 'leave-job-submitted' : ($leaveStep['errno'] !== 0 ? 'leave-job-request-error' : 'leave-job-rejected'),
				'actionUrl' => (string) ($leaveStep['url'] ?: $leaveActionUrl),
				'httpStatus' => (int) $leaveStep['statusCode'],
				'error' => (string) $leaveStep['error'],
			];

			$step3 = curlRequest($ch, $baseUrl, [
				CURLOPT_POST => false,
				CURLOPT_HTTPGET => true,
				CURLOPT_HTTPHEADER => $headers,
			]);

			if ($step3['errno'] !== 0) {
				$fatalError = 'GET post-leave fallo: ' . $step3['error'];
			}
		}
	} else {
		$leaveJobResult['reason'] = $workplaceStep['errno'] !== 0 ? 'work2-request-error' : 'work2-http-error';
		$leaveJobResult['httpStatus'] = (int) $workplaceStep['statusCode'];
	}
}

if ($fatalError === '' && $companyOffersLoadRequested) {
	$rawCompanyUrl = trim((string) ($_POST['company_url'] ?? ''));
	$normalizedCompanyUrl = normalizeCompanyPageUrl($rawCompanyUrl, (string) ($step3['effectiveUrl'] ?? serverUrl('index.html')));

	$companyOffersResult = [
		'attempted' => true,
		'saved' => false,
		'reason' => $normalizedCompanyUrl === '' ? 'company-url-invalid' : 'company-offers-request-error',
		'httpStatus' => 0,
		'sourceUrl' => $normalizedCompanyUrl,
		'companyName' => '',
		'offers' => [],
		'error' => '',
	];

	if ($normalizedCompanyUrl !== '') {
		$companyStep = curlRequest($ch, $normalizedCompanyUrl, [
			CURLOPT_POST => false,
			CURLOPT_HTTPGET => true,
			CURLOPT_HTTPHEADER => $headers,
		]);

		$offersExtracted = extractCompanyJobOffersFromCompanyHtml((string) ($companyStep['body'] ?? ''), (string) ($companyStep['effectiveUrl'] ?: $normalizedCompanyUrl));
		$companyOk = $companyStep['errno'] === 0
			&& (int) $companyStep['statusCode'] >= 200
			&& (int) $companyStep['statusCode'] < 400;

		$companyOffersResult = [
			'attempted' => true,
			'saved' => $companyOk,
			'reason' => $companyOk
				? ((is_array($offersExtracted['offers'] ?? null) && count((array) ($offersExtracted['offers'] ?? [])) > 0) ? 'company-offers-loaded' : 'company-offers-empty')
				: ($companyStep['errno'] !== 0 ? 'company-offers-request-error' : 'company-offers-http-error'),
			'httpStatus' => (int) ($companyStep['statusCode'] ?? 0),
			'sourceUrl' => (string) ($companyStep['effectiveUrl'] ?: $normalizedCompanyUrl),
			'companyName' => (string) ($offersExtracted['companyName'] ?? ''),
			'offers' => is_array($offersExtracted['offers'] ?? null) ? (array) $offersExtracted['offers'] : [],
			'error' => (string) ($companyStep['error'] ?? ''),
		];
	}
}

if ($fatalError === '' && $companyOfferApplyRequested) {
	$offerId = trim((string) ($_POST['offer_id'] ?? ''));
	$countryId = trim((string) ($_POST['offer_country_id'] ?? ''));
	$actionUrlRaw = trim((string) ($_POST['offer_apply_action_url'] ?? ''));
	$offerRefererUrl = trim((string) ($_POST['offer_referer_url'] ?? ''));
	$actionUrl = $actionUrlRaw !== ''
		? resolveUrl($offerRefererUrl !== '' ? $offerRefererUrl : serverUrl('company.html'), $actionUrlRaw)
		: '';

	$companyOfferApplyResult = [
		'attempted' => true,
		'saved' => false,
		'reason' => (!preg_match('/^\d+$/', $offerId) || $actionUrl === '') ? 'job-offer-invalid-data' : 'job-offer-apply-request-error',
		'actionUrl' => $actionUrl,
		'httpStatus' => 0,
		'offerId' => $offerId,
		'error' => '',
	];

	if (preg_match('/^\d+$/', $offerId) && $actionUrl !== '') {
		$applyStep = submitJobOfferApply(
			$ch,
			$offerRefererUrl !== '' ? $offerRefererUrl : (string) ($step3['effectiveUrl'] ?: serverUrl('index.html')),
			$headers,
			$actionUrl,
			$offerId,
			$countryId
		);

		$applyBody = (string) ($applyStep['body'] ?? '');
		$looksSuccessText = preg_match('/\bsuccess\b/i', $applyBody) === 1;
		$looksFailureText = preg_match('/\b(error|failed|forbidden|invalid|not\s+enough|already)\b/i', $applyBody) === 1;
		$applyAccepted = $applyStep['errno'] === 0
			&& (int) $applyStep['statusCode'] >= 200
			&& (int) $applyStep['statusCode'] < 400
			&& ($looksSuccessText || !$looksFailureText);

		$companyOfferApplyResult = [
			'attempted' => true,
			'saved' => $applyAccepted,
			'reason' => $applyAccepted ? 'job-offer-apply-submitted' : ($applyStep['errno'] !== 0 ? 'job-offer-apply-request-error' : 'job-offer-apply-rejected'),
			'actionUrl' => (string) ($applyStep['url'] ?: $actionUrl),
			'httpStatus' => (int) ($applyStep['statusCode'] ?? 0),
			'offerId' => $offerId,
			'error' => (string) ($applyStep['error'] ?? ''),
		];
	}
}

if ($fatalError === '' && $notificationsRequested) {
	$notificationsStep = curlRequest($ch, $notificationsUrl, [
		CURLOPT_POST => false,
		CURLOPT_HTTPGET => true,
		CURLOPT_HTTPHEADER => $headers,
	]);

	$notificationsBody = (string) ($notificationsStep['body'] ?? '');
	$notificationsBodyLength = strlen($notificationsBody);
	$notificationsOk = $notificationsStep['errno'] === 0
		&& (int) $notificationsStep['statusCode'] >= 200
		&& (int) $notificationsStep['statusCode'] < 400
		&& trim($notificationsBody) !== '';
	$notificationsItems = $notificationsOk
		? extractNotificationsListFromHtml($notificationsBody, (string) ($notificationsStep['effectiveUrl'] ?: $notificationsUrl))
		: [];

	$notificationsResult = [
		'attempted' => true,
		'saved' => $notificationsOk,
		'reason' => $notificationsOk
			? (count($notificationsItems) > 0 ? 'notifications-loaded' : 'notifications-empty')
			: ($notificationsStep['errno'] !== 0 ? 'notifications-request-error' : 'notifications-http-error'),
		'httpStatus' => (int) ($notificationsStep['statusCode'] ?? 0),
		'url' => (string) ($notificationsStep['effectiveUrl'] ?: $notificationsUrl),
		'bodyLength' => $notificationsBodyLength,
		'items' => $notificationsItems,
		'itemsCount' => count($notificationsItems),
		'error' => (string) ($notificationsStep['error'] ?? ''),
	];
}

if ($fatalError === '' && ($dailiesLoadRequested || $dailiesClaimRequested)) {
	$dailiesStep = curlRequest($ch, $dailiesUrl, [
		CURLOPT_POST => false,
		CURLOPT_HTTPGET => true,
		CURLOPT_HTTPHEADER => $headers,
	]);

	$dailiesBody = (string) ($dailiesStep['body'] ?? '');
	$dailiesBodyLength = strlen($dailiesBody);
	$dailiesOk = $dailiesStep['errno'] === 0
		&& (int) ($dailiesStep['statusCode'] ?? 0) >= 200
		&& (int) ($dailiesStep['statusCode'] ?? 0) < 400
		&& trim($dailiesBody) !== '';
	$dailiesItems = $dailiesOk
		? extractDailiesFromHtml($dailiesBody, (string) ($dailiesStep['effectiveUrl'] ?: $dailiesUrl))
		: [];
	$claimableCount = 0;
	foreach ($dailiesItems as $dailiesItem) {
		if (!empty($dailiesItem['isClaimable'])) {
			$claimableCount++;
		}
	}

	$dailiesResult = [
		'attempted' => true,
		'saved' => $dailiesOk,
		'reason' => $dailiesOk
			? (count($dailiesItems) > 0 ? 'dailies-loaded' : 'dailies-empty')
			: ($dailiesStep['errno'] !== 0 ? 'dailies-request-error' : 'dailies-http-error'),
		'httpStatus' => (int) ($dailiesStep['statusCode'] ?? 0),
		'url' => (string) ($dailiesStep['effectiveUrl'] ?: $dailiesUrl),
		'bodyLength' => $dailiesBodyLength,
		'items' => $dailiesItems,
		'itemsCount' => count($dailiesItems),
		'claimableCount' => $claimableCount,
		'error' => (string) ($dailiesStep['error'] ?? ''),
	];
}

if ($fatalError === '' && $dailiesClaimRequested) {
	$postedDailyId = preg_replace('/\D+/', '', trim((string) ($_POST['daily_id'] ?? '')));
	$postedClaimUrl = trim((string) ($_POST['daily_claim_url'] ?? ''));
	$claimAllowed = false;

	if ($postedDailyId === '' && is_array($dailiesResult['items'] ?? null)) {
		foreach ((array) ($dailiesResult['items'] ?? []) as $dailyItem) {
			if (!empty($dailyItem['isClaimable'])) {
				$postedDailyId = preg_replace('/\D+/', '', trim((string) ($dailyItem['dailyId'] ?? '')));
				if ($postedClaimUrl === '') {
					$postedClaimUrl = trim((string) ($dailyItem['claimUrl'] ?? ''));
				}
				if ($postedDailyId !== '' || !empty($dailyItem['isChest'])) {
					$claimAllowed = true;
					break;
				}
			}
		}
	}

	if (!$claimAllowed && is_array($dailiesResult['items'] ?? null)) {
		foreach ((array) ($dailiesResult['items'] ?? []) as $dailyItem) {
			if (empty($dailyItem['isClaimable'])) {
				continue;
			}

			$itemDailyId = preg_replace('/\D+/', '', trim((string) ($dailyItem['dailyId'] ?? '')));
			if ($postedDailyId !== '' && $itemDailyId === $postedDailyId) {
				$claimAllowed = true;
				if ($postedClaimUrl === '') {
					$postedClaimUrl = trim((string) ($dailyItem['claimUrl'] ?? ''));
				}
				break;
			}

			if ($postedDailyId === '' && !empty($dailyItem['isChest'])) {
				$claimAllowed = true;
				if ($postedClaimUrl === '') {
					$postedClaimUrl = trim((string) ($dailyItem['claimUrl'] ?? ''));
				}
				break;
			}
		}
	}

	if ($claimAllowed) {
		$dailiesClaimResult = submitDailyMissionClaim(
			$ch,
			trim((string) ($dailiesResult['url'] ?? $dailiesUrl)),
			$headers,
			$postedClaimUrl,
			$postedDailyId
		);
	} else {
		$dailiesClaimResult = [
			'attempted' => true,
			'saved' => false,
			'reason' => 'dailies-claim-button-not-available',
			'httpStatus' => 0,
			'url' => '',
			'claimUrl' => $postedClaimUrl,
			'dailyId' => $postedDailyId,
			'responseSnippet' => '',
			'error' => '',
		];
	}

	$dailiesRefreshStep = curlRequest($ch, $dailiesUrl, [
		CURLOPT_POST => false,
		CURLOPT_HTTPGET => true,
		CURLOPT_HTTPHEADER => $headers,
	]);
	$dailiesRefreshBody = (string) ($dailiesRefreshStep['body'] ?? '');
	$dailiesRefreshOk = $dailiesRefreshStep['errno'] === 0
		&& (int) ($dailiesRefreshStep['statusCode'] ?? 0) >= 200
		&& (int) ($dailiesRefreshStep['statusCode'] ?? 0) < 400
		&& trim($dailiesRefreshBody) !== '';
	$dailiesRefreshItems = $dailiesRefreshOk
		? extractDailiesFromHtml($dailiesRefreshBody, (string) ($dailiesRefreshStep['effectiveUrl'] ?: $dailiesUrl))
		: (array) ($dailiesResult['items'] ?? []);
	$dailiesRefreshClaimableCount = 0;
	foreach ($dailiesRefreshItems as $dailiesRefreshItem) {
		if (!empty($dailiesRefreshItem['isClaimable'])) {
			$dailiesRefreshClaimableCount++;
		}
	}

	$dailiesResult = [
		'attempted' => true,
		'saved' => $dailiesRefreshOk,
		'reason' => $dailiesRefreshOk
			? (count($dailiesRefreshItems) > 0 ? 'dailies-loaded' : 'dailies-empty')
			: ($dailiesRefreshStep['errno'] !== 0 ? 'dailies-request-error' : 'dailies-http-error'),
		'httpStatus' => (int) ($dailiesRefreshStep['statusCode'] ?? 0),
		'url' => (string) ($dailiesRefreshStep['effectiveUrl'] ?: $dailiesUrl),
		'bodyLength' => strlen($dailiesRefreshBody),
		'items' => $dailiesRefreshItems,
		'itemsCount' => count($dailiesRefreshItems),
		'claimableCount' => $dailiesRefreshClaimableCount,
		'error' => (string) ($dailiesRefreshStep['error'] ?? ''),
	];
}

if ($fatalError === '' && $changeEmailRequested) {
	$newEmail = trim((string) ($_POST['change_email'] ?? ''));
	$changeEmailUrl = serverUrl('editCitizen.html');

	$changeEmailResult = [
		'attempted' => true,
		'saved' => false,
		'reason' => 'change-email-invalid',
		'httpStatus' => 0,
		'url' => $changeEmailUrl,
		'email' => $newEmail,
		'responseSnippet' => '',
		'error' => '',
	];

	if (filter_var($newEmail, FILTER_VALIDATE_EMAIL) !== false) {
		$changeEmailStep = curlRequest($ch, $changeEmailUrl, [
			CURLOPT_POST => true,
			CURLOPT_HTTPGET => false,
			CURLOPT_POSTFIELDS => http_build_query([
				'action' => 'CHANGE_EMAIL',
				'mail' => $newEmail,
			]),
			CURLOPT_HTTPHEADER => array_merge($headers, [
				'Content-Type: application/x-www-form-urlencoded',
				'Origin: ' . rtrim(serverUrl(''), '/'),
				'Referer: ' . (string) ($step3['effectiveUrl'] ?: serverUrl('index.html')),
			]),
		]);

		$changeBody = (string) ($changeEmailStep['body'] ?? '');
		$changeBodyLower = strtolower($changeBody);
		$changeHttpOk = $changeEmailStep['errno'] === 0
			&& (int) ($changeEmailStep['statusCode'] ?? 0) >= 200
			&& (int) ($changeEmailStep['statusCode'] ?? 0) < 400;
		$changeLooksSuccess = preg_match('/(changed|change e-mail|email changed|success|updated)/i', $changeBody) === 1;
		$changeLooksError = preg_match('/(error|invalid|already|forbidden|failed|captcha|not logged)/i', $changeBody) === 1;

		$changeEmailResult = [
			'attempted' => true,
			'saved' => $changeHttpOk && ($changeLooksSuccess || (!$changeLooksError && trim($changeBodyLower) !== '')),
			'reason' => !$changeHttpOk
				? ((int) ($changeEmailStep['errno'] ?? 0) !== 0 ? 'change-email-request-error' : 'change-email-http-error')
				: ($changeLooksSuccess ? 'change-email-submitted' : ($changeLooksError ? 'change-email-rejected' : 'change-email-processed')),
			'httpStatus' => (int) ($changeEmailStep['statusCode'] ?? 0),
			'url' => (string) ($changeEmailStep['effectiveUrl'] ?: $changeEmailUrl),
			'email' => $newEmail,
			'responseSnippet' => trim(substr(compactNodeText($changeBody), 0, 260)),
			'error' => (string) ($changeEmailStep['error'] ?? ''),
		];
	}
}

if ($fatalError === '' && $resendConfirmationMailRequested) {
	$resendConfirmationMailUrl = serverUrl('resendConfirmationMail.html');
	$resendStep = curlRequest($ch, $resendConfirmationMailUrl, [
		CURLOPT_POST => false,
		CURLOPT_HTTPGET => true,
		CURLOPT_HTTPHEADER => $headers,
	]);

	$resendBody = (string) ($resendStep['body'] ?? '');
	$resendBodyLower = strtolower($resendBody);
	$resendHttpOk = $resendStep['errno'] === 0
		&& (int) ($resendStep['statusCode'] ?? 0) >= 200
		&& (int) ($resendStep['statusCode'] ?? 0) < 400;
	$resendLooksError = preg_match('/(error|invalid|forbidden|failed|captcha|not logged|denied)/i', $resendBody) === 1;
	$resendLooksSuccess = preg_match('/(mail|email).*(sent|resent|success)|confirmation.*(sent|resent)/i', $resendBody) === 1;

	$resendConfirmationMailResult = [
		'attempted' => true,
		'saved' => $resendHttpOk && ($resendLooksSuccess || (!$resendLooksError && trim($resendBodyLower) !== '')),
		'reason' => !$resendHttpOk
			? ((int) ($resendStep['errno'] ?? 0) !== 0 ? 'resend-confirmation-request-error' : 'resend-confirmation-http-error')
			: ($resendLooksSuccess ? 'resend-confirmation-sent' : ($resendLooksError ? 'resend-confirmation-rejected' : 'resend-confirmation-processed')),
		'httpStatus' => (int) ($resendStep['statusCode'] ?? 0),
		'url' => (string) ($resendStep['effectiveUrl'] ?: $resendConfirmationMailUrl),
		'responseSnippet' => trim(substr(compactNodeText($resendBody), 0, 260)),
		'error' => (string) ($resendStep['error'] ?? ''),
	];
}

if ($fatalError === '' && $confirmMailCodeRequested) {
	$postedStamp = trim((string) ($_POST['confirm_mail_code'] ?? ''));
	$postedCitizenId = preg_replace('/\D+/', '', trim((string) ($_POST['confirm_citizen_id'] ?? '')));
	$fallbackCitizenId = preg_replace('/\D+/', '', trim((string) $userId));
	if ($postedCitizenId === '') {
		$previewInfoForConfirm = extractLoggedPlayerInfo((string) ($step3['body'] ?? ''));
		$previewCitizenId = preg_replace('/\D+/', '', trim((string) ($previewInfoForConfirm['citizenId'] ?? '')));
		$postedCitizenId = $previewCitizenId !== '' ? $previewCitizenId : $fallbackCitizenId;
	}

	$confirmMailCodeResult = [
		'attempted' => true,
		'saved' => false,
		'reason' => $postedCitizenId === '' ? 'confirm-mail-citizen-id-missing' : 'confirm-mail-invalid-stamp',
		'httpStatus' => 0,
		'url' => '',
		'citizenId' => $postedCitizenId,
		'stamp' => $postedStamp,
		'responseSnippet' => '',
		'error' => '',
	];

	if ($postedStamp !== '' && $postedCitizenId !== '') {
		$confirmMailUrl = serverUrl('confirmMail.html?') . http_build_query([
			'citizenId' => $postedCitizenId,
			'stamp' => $postedStamp,
		]);

		$confirmStep = curlRequest($ch, $confirmMailUrl, [
			CURLOPT_POST => false,
			CURLOPT_HTTPGET => true,
			CURLOPT_HTTPHEADER => $headers,
		]);

		$confirmBody = (string) ($confirmStep['body'] ?? '');
		$confirmBodyLower = strtolower($confirmBody);
		$confirmHttpOk = $confirmStep['errno'] === 0
			&& (int) ($confirmStep['statusCode'] ?? 0) >= 200
			&& (int) ($confirmStep['statusCode'] ?? 0) < 400;
		$confirmLooksSuccess = preg_match('/(mail\s+confirmed|email\s+confirmed|confirmation\s+successful|success|already\s+confirmed)/i', $confirmBody) === 1;
		$confirmLooksError = preg_match('/(error|invalid|forbidden|failed|not\s+logged|denied|expired|wrong\s+code|incorrect)/i', $confirmBody) === 1;

		$confirmMailCodeResult = [
			'attempted' => true,
			'saved' => $confirmHttpOk && ($confirmLooksSuccess || (!$confirmLooksError && trim($confirmBodyLower) !== '')),
			'reason' => !$confirmHttpOk
				? ((int) ($confirmStep['errno'] ?? 0) !== 0 ? 'confirm-mail-request-error' : 'confirm-mail-http-error')
				: ($confirmLooksSuccess ? 'confirm-mail-confirmed' : ($confirmLooksError ? 'confirm-mail-rejected' : 'confirm-mail-processed')),
			'httpStatus' => (int) ($confirmStep['statusCode'] ?? 0),
			'url' => (string) ($confirmStep['effectiveUrl'] ?: $confirmMailUrl),
			'citizenId' => $postedCitizenId,
			'stamp' => $postedStamp,
			'responseSnippet' => trim(substr(compactNodeText($confirmBody), 0, 260)),
			'error' => (string) ($confirmStep['error'] ?? ''),
		];
	}
}

if ($fatalError === '' && $partyStatusCheckRequested) {
	$partyUrl = serverUrl('myParty.html');
	$partyStep = curlRequest($ch, $partyUrl, [
		CURLOPT_POST => false,
		CURLOPT_HTTPGET => true,
		CURLOPT_HTTPHEADER => $headers,
	]);

	$partyBody = (string) ($partyStep['body'] ?? '');
	$partyBodyCompact = compactNodeText($partyBody);
	$partyHttpOk = $partyStep['errno'] === 0
		&& (int) ($partyStep['statusCode'] ?? 0) >= 200
		&& (int) ($partyStep['statusCode'] ?? 0) < 400;
	$partyNeedsEmailConfirmation = stripos($partyBody, 'You need to confirm your email to join a political party') !== false;

	$partyStatusCheckResult = [
		'attempted' => true,
		'saved' => $partyHttpOk,
		'reason' => !$partyHttpOk
			? ((int) ($partyStep['errno'] ?? 0) !== 0 ? 'party-status-request-error' : 'party-status-http-error')
			: ($partyNeedsEmailConfirmation ? 'party-email-confirmation-required' : 'party-status-checked'),
		'httpStatus' => (int) ($partyStep['statusCode'] ?? 0),
		'url' => (string) ($partyStep['effectiveUrl'] ?: $partyUrl),
		'needsEmailConfirmation' => $partyNeedsEmailConfirmation,
		'responseSnippet' => trim(substr($partyBodyCompact, 0, 260)),
		'error' => (string) ($partyStep['error'] ?? ''),
	];
}

if ($fatalError === '' && $partyInspectRequested) {
	$partyUrlRaw = trim((string) ($_POST['party_url'] ?? ''));
	$partyUrl = normalizePartyPageUrl($partyUrlRaw, (string) ($step3['effectiveUrl'] ?? serverUrl('index.html')));

	$partyInspectResult = [
		'attempted' => true,
		'saved' => false,
		'reason' => $partyUrl === '' ? 'party-url-invalid' : 'party-inspect-request-error',
		'httpStatus' => 0,
		'url' => $partyUrl,
		'partyName' => '',
		'joinDetected' => false,
		'hasJoinForm' => false,
		'hasJoinButton' => false,
		'joinActionUrl' => '',
		'joinMethod' => '',
		'joinFields' => [],
		'joinIndicator' => '',
		'leaveDetected' => false,
		'hasLeaveForm' => false,
		'leaveActionUrl' => '',
		'leaveMethod' => '',
		'leaveFields' => [],
		'responseSnippet' => '',
		'responseHtml' => '',
		'error' => '',
	];

	if ($partyUrl !== '') {
		$partyStep = curlRequest($ch, $partyUrl, [
			CURLOPT_POST => false,
			CURLOPT_HTTPGET => true,
			CURLOPT_HTTPHEADER => $headers,
		]);

		$partyBody = (string) ($partyStep['body'] ?? '');
		$partyHttpOk = $partyStep['errno'] === 0
			&& (int) ($partyStep['statusCode'] ?? 0) >= 200
			&& (int) ($partyStep['statusCode'] ?? 0) < 400
			&& trim($partyBody) !== '';
		$joinInfo = $partyHttpOk
			? extractPartyJoinAvailabilityFromHtml($partyBody, (string) ($partyStep['effectiveUrl'] ?: $partyUrl))
			: [
				'partyName' => '',
				'joinDetected' => false,
				'hasJoinForm' => false,
				'hasJoinButton' => false,
				'joinActionUrl' => '',
				'joinMethod' => '',
				'joinFields' => [],
				'joinIndicator' => '',
				'leaveDetected' => false,
				'hasLeaveForm' => false,
				'leaveActionUrl' => '',
				'leaveMethod' => '',
				'leaveFields' => [],
			];

		$partyInspectResult = [
			'attempted' => true,
			'saved' => $partyHttpOk,
			'reason' => !$partyHttpOk
				? ((int) ($partyStep['errno'] ?? 0) !== 0 ? 'party-inspect-request-error' : 'party-inspect-http-error')
				: (!empty($joinInfo['joinDetected'])
					? 'party-join-control-found'
					: (!empty($joinInfo['leaveDetected']) ? 'party-leave-control-found' : 'party-join-control-not-found')),
			'httpStatus' => (int) ($partyStep['statusCode'] ?? 0),
			'url' => (string) ($partyStep['effectiveUrl'] ?: $partyUrl),
			'partyName' => (string) ($joinInfo['partyName'] ?? ''),
			'joinDetected' => !empty($joinInfo['joinDetected']),
			'hasJoinForm' => !empty($joinInfo['hasJoinForm']),
			'hasJoinButton' => !empty($joinInfo['hasJoinButton']),
			'joinActionUrl' => (string) ($joinInfo['joinActionUrl'] ?? ''),
			'joinMethod' => (string) ($joinInfo['joinMethod'] ?? ''),
			'joinFields' => is_array($joinInfo['joinFields'] ?? null) ? (array) $joinInfo['joinFields'] : [],
			'joinIndicator' => (string) ($joinInfo['joinIndicator'] ?? ''),
			'leaveDetected' => !empty($joinInfo['leaveDetected']),
			'hasLeaveForm' => !empty($joinInfo['hasLeaveForm']),
			'leaveActionUrl' => (string) ($joinInfo['leaveActionUrl'] ?? ''),
			'leaveMethod' => (string) ($joinInfo['leaveMethod'] ?? ''),
			'leaveFields' => is_array($joinInfo['leaveFields'] ?? null) ? (array) $joinInfo['leaveFields'] : [],
			'responseSnippet' => trim(substr(compactNodeText($partyBody), 0, 280)),
			'responseHtml' => $partyBody,
			'error' => (string) ($partyStep['error'] ?? ''),
		];
	}
}

if ($fatalError === '' && $partyJoinRequested) {
	$partyName = trim((string) ($_POST['party_name'] ?? ''));
	$joinActionUrl = trim((string) ($_POST['party_join_action_url'] ?? ''));
	$joinMethod = strtoupper(trim((string) ($_POST['party_join_method'] ?? 'POST')));
	$joinChoice = trim((string) ($_POST['party_join_choice'] ?? 'yes'));
	$joinFieldsEncoded = trim((string) ($_POST['party_join_fields_encoded'] ?? ''));
	$joinFieldsDecoded = $joinFieldsEncoded !== '' ? base64_decode($joinFieldsEncoded, true) : '';
	$joinFields = is_string($joinFieldsDecoded) ? json_decode($joinFieldsDecoded, true) : [];
	if (!is_array($joinFields)) {
		$joinFields = [];
	}

	$joinFieldAction = strtoupper(trim((string) ($joinFields['action'] ?? '')));
	$joinFieldId = preg_replace('/\D+/', '', trim((string) ($joinFields['id'] ?? '')));
	if ($joinFieldAction === 'JOIN' && $joinFieldId !== '') {
		$joinMethod = 'POST';
		$joinActionUrl = resolveUrl(
			$joinActionUrl !== '' ? $joinActionUrl : serverUrl('partyStatistics.html'),
			'partyStatistics.html'
		);
		$joinFields = [
			'action' => 'JOIN',
			'id' => $joinFieldId,
		];
	}

	$partyJoinResult = [
		'attempted' => true,
		'saved' => false,
		'reason' => $joinChoice === 'yes' ? 'party-join-missing-action' : 'party-join-not-confirmed',
		'httpStatus' => 0,
		'url' => '',
		'partyName' => $partyName,
		'joinActionUrl' => $joinActionUrl,
		'joinMethod' => $joinMethod,
		'joinReferer' => trim((string) ($_POST['party_url'] ?? '')),
		'joinChoice' => $joinChoice,
		'curlErrno' => 0,
		'totalTime' => 0.0,
		'requestPayload' => $joinFields !== [] ? http_build_query($joinFields) : '',
		'responseSnippet' => '',
		'responseHtml' => '',
		'error' => '',
	];

	if ($joinChoice === 'yes' && $joinActionUrl !== '') {
		if ($joinMethod !== 'GET' && $joinMethod !== 'POST') {
			$joinMethod = 'POST';
		}

		$joinReferer = trim((string) ($_POST['party_url'] ?? ''));
		if ($joinReferer === '') {
			$joinReferer = (string) ($step3['effectiveUrl'] ?? serverUrl('index.html'));
		}

		$joinHeaders = array_merge($headers, [
			'Origin: ' . rtrim(serverUrl(''), '/'),
			'Referer: ' . $joinReferer,
		]);

		if ($joinMethod === 'GET') {
			$joinUrlWithParams = $joinActionUrl;
			if ($joinFields !== []) {
				$joinUrlWithParams .= (str_contains($joinUrlWithParams, '?') ? '&' : '?') . http_build_query($joinFields);
			}
			$joinStep = curlRequest($ch, $joinUrlWithParams, [
				CURLOPT_POST => false,
				CURLOPT_HTTPGET => true,
				CURLOPT_HTTPHEADER => $joinHeaders,
			]);
		} else {
			$joinStep = curlRequest($ch, $joinActionUrl, [
				CURLOPT_POST => true,
				CURLOPT_HTTPGET => false,
				CURLOPT_POSTFIELDS => http_build_query($joinFields),
				CURLOPT_HTTPHEADER => array_merge($joinHeaders, [
					'Content-Type: application/x-www-form-urlencoded',
				]),
			]);
		}

		$joinBody = (string) ($joinStep['body'] ?? '');
		$joinBodyLower = strtolower($joinBody);
		$joinHttpOk = $joinStep['errno'] === 0
			&& (int) ($joinStep['statusCode'] ?? 0) >= 200
			&& (int) ($joinStep['statusCode'] ?? 0) < 400;
		$joinLooksSuccess = preg_match('/(joined|join request sent|application sent|success|you joined)/i', $joinBody) === 1;
		$joinLooksError = preg_match('/(error|failed|forbidden|denied|cannot join|not logged|already in a party|need to confirm your email)/i', $joinBody) === 1;

		$partyJoinResult = [
			'attempted' => true,
			'saved' => $joinHttpOk && ($joinLooksSuccess || (!$joinLooksError && trim($joinBodyLower) !== '')),
			'reason' => !$joinHttpOk
				? ((int) ($joinStep['errno'] ?? 0) !== 0 ? 'party-join-request-error' : 'party-join-http-error')
				: ($joinLooksSuccess ? 'party-join-submitted' : ($joinLooksError ? 'party-join-rejected' : 'party-join-processed')),
			'httpStatus' => (int) ($joinStep['statusCode'] ?? 0),
			'url' => (string) ($joinStep['effectiveUrl'] ?: $joinActionUrl),
			'partyName' => $partyName,
			'joinActionUrl' => $joinActionUrl,
			'joinMethod' => $joinMethod,
			'joinReferer' => $joinReferer,
			'joinChoice' => $joinChoice,
			'curlErrno' => (int) ($joinStep['errno'] ?? 0),
			'totalTime' => (float) ($joinStep['totalTime'] ?? 0),
			'requestPayload' => $joinFields !== [] ? http_build_query($joinFields) : '',
			'responseSnippet' => trim(substr(compactNodeText($joinBody), 0, 280)),
			'responseHtml' => $joinBody,
			'error' => (string) ($joinStep['error'] ?? ''),
		];
	}
}

if ($fatalError === '' && $partyLeaveRequested) {
	$partyName = trim((string) ($_POST['party_name'] ?? ''));
	$leaveActionUrl = trim((string) ($_POST['party_leave_action_url'] ?? ''));
	$leaveMethod = strtoupper(trim((string) ($_POST['party_leave_method'] ?? 'POST')));
	$leaveFieldsEncoded = trim((string) ($_POST['party_leave_fields_encoded'] ?? ''));
	$leaveFieldsDecoded = $leaveFieldsEncoded !== '' ? base64_decode($leaveFieldsEncoded, true) : '';
	$leaveFields = is_string($leaveFieldsDecoded) ? json_decode($leaveFieldsDecoded, true) : [];
	if (!is_array($leaveFields)) {
		$leaveFields = [];
	}

	if ($leaveActionUrl === '') {
		$leaveActionUrl = serverUrl('partyStatistics.html');
	}
	$leaveActionUrl = resolveUrl($leaveActionUrl, 'partyStatistics.html');
	if ($leaveMethod !== 'POST' && $leaveMethod !== 'GET') {
		$leaveMethod = 'POST';
	}

	if (!isset($leaveFields['action']) || strtoupper(trim((string) $leaveFields['action'])) !== 'LEAVE') {
		$leaveFields['action'] = 'LEAVE';
	}

	$leaveReferer = trim((string) ($_POST['party_url'] ?? ''));
	if ($leaveReferer === '') {
		$leaveReferer = (string) ($step3['effectiveUrl'] ?? serverUrl('index.html'));
	}

	$leaveHeaders = array_merge($headers, [
		'Origin: ' . rtrim(serverUrl(''), '/'),
		'Referer: ' . $leaveReferer,
	]);

	if ($leaveMethod === 'GET') {
		$leaveUrlWithParams = $leaveActionUrl;
		if ($leaveFields !== []) {
			$leaveUrlWithParams .= (str_contains($leaveUrlWithParams, '?') ? '&' : '?') . http_build_query($leaveFields);
		}
		$leaveStep = curlRequest($ch, $leaveUrlWithParams, [
			CURLOPT_POST => false,
			CURLOPT_HTTPGET => true,
			CURLOPT_HTTPHEADER => $leaveHeaders,
		]);
	} else {
		$leaveStep = curlRequest($ch, $leaveActionUrl, [
			CURLOPT_POST => true,
			CURLOPT_HTTPGET => false,
			CURLOPT_POSTFIELDS => http_build_query($leaveFields),
			CURLOPT_HTTPHEADER => array_merge($leaveHeaders, [
				'Content-Type: application/x-www-form-urlencoded',
			]),
		]);
	}

	$leaveBody = (string) ($leaveStep['body'] ?? '');
	$leaveHttpOk = $leaveStep['errno'] === 0
		&& (int) ($leaveStep['statusCode'] ?? 0) >= 200
		&& (int) ($leaveStep['statusCode'] ?? 0) < 400;
	$leaveLooksSuccess = preg_match('/(left party|you left|success|saved|done)/i', $leaveBody) === 1;
	$leaveLooksError = preg_match('/(error|failed|forbidden|denied|cannot|not logged)/i', $leaveBody) === 1;

	$partyLeaveResult = [
		'attempted' => true,
		'saved' => $leaveHttpOk && ($leaveLooksSuccess || (!$leaveLooksError && trim($leaveBody) !== '')),
		'reason' => !$leaveHttpOk
			? ((int) ($leaveStep['errno'] ?? 0) !== 0 ? 'party-leave-request-error' : 'party-leave-http-error')
			: ($leaveLooksSuccess ? 'party-leave-submitted' : ($leaveLooksError ? 'party-leave-rejected' : 'party-leave-processed')),
		'httpStatus' => (int) ($leaveStep['statusCode'] ?? 0),
		'url' => (string) ($leaveStep['effectiveUrl'] ?: $leaveActionUrl),
		'partyName' => $partyName,
		'leaveActionUrl' => $leaveActionUrl,
		'leaveMethod' => $leaveMethod,
		'requestPayload' => $leaveFields !== [] ? http_build_query($leaveFields) : '',
		'responseSnippet' => trim(substr(compactNodeText($leaveBody), 0, 280)),
		'responseHtml' => $leaveBody,
		'error' => (string) ($leaveStep['error'] ?? ''),
	];
}

if ($fatalError === '' && looksAuthenticated((string) ($step3['body'] ?? ''))) {
	$registeredEmailUrl = serverUrl('editCitizen.html?editCitizenPage=PERSONAL_DATA');
	$registeredEmailStep = curlRequest($ch, $registeredEmailUrl, [
		CURLOPT_POST => false,
		CURLOPT_HTTPGET => true,
		CURLOPT_HTTPHEADER => $headers,
	]);

	$registeredEmailBody = (string) ($registeredEmailStep['body'] ?? '');
	$registeredEmailHttpOk = $registeredEmailStep['errno'] === 0
		&& (int) ($registeredEmailStep['statusCode'] ?? 0) >= 200
		&& (int) ($registeredEmailStep['statusCode'] ?? 0) < 400
		&& trim($registeredEmailBody) !== '';
	$registeredEmailValue = $registeredEmailHttpOk ? extractRegisteredEmailFromPersonalDataHtml($registeredEmailBody) : '';

	$registeredEmailResult = [
		'attempted' => true,
		'saved' => $registeredEmailHttpOk,
		'reason' => !$registeredEmailHttpOk
			? ((int) ($registeredEmailStep['errno'] ?? 0) !== 0 ? 'registered-email-request-error' : 'registered-email-http-error')
			: ($registeredEmailValue !== '' ? 'registered-email-loaded' : 'registered-email-empty'),
		'httpStatus' => (int) ($registeredEmailStep['statusCode'] ?? 0),
		'url' => (string) ($registeredEmailStep['effectiveUrl'] ?: $registeredEmailUrl),
		'email' => $registeredEmailValue,
		'error' => (string) ($registeredEmailStep['error'] ?? ''),
	];
}

if ($fatalError === '' && looksAuthenticated((string) ($step3['body'] ?? ''))) {
	$storageMoneyStep = curlRequest($ch, $storageMoneyUrl, [
		CURLOPT_POST => false,
		CURLOPT_HTTPGET => true,
		CURLOPT_HTTPHEADER => $headers,
	]);

	$storageMoneyBody = (string) ($storageMoneyStep['body'] ?? '');
	$storageMoneyBodyLength = strlen($storageMoneyBody);
	$storageMoneyOk = $storageMoneyStep['errno'] === 0
		&& (int) ($storageMoneyStep['statusCode'] ?? 0) >= 200
		&& (int) ($storageMoneyStep['statusCode'] ?? 0) < 400
		&& trim($storageMoneyBody) !== '';
	$storageAccounts = $storageMoneyOk
		? extractStorageMoneyAccountsFromHtml($storageMoneyBody)
		: [];

	$storageMoneyResult = [
		'attempted' => true,
		'saved' => $storageMoneyOk,
		'reason' => $storageMoneyOk
			? (count($storageAccounts) > 0 ? 'storage-money-loaded' : 'storage-money-empty')
			: ($storageMoneyStep['errno'] !== 0 ? 'storage-money-request-error' : 'storage-money-http-error'),
		'httpStatus' => (int) ($storageMoneyStep['statusCode'] ?? 0),
		'url' => (string) ($storageMoneyStep['effectiveUrl'] ?: $storageMoneyUrl),
		'bodyLength' => $storageMoneyBodyLength,
		'accounts' => $storageAccounts,
		'accountsCount' => count($storageAccounts),
		'error' => (string) ($storageMoneyStep['error'] ?? ''),
	];
}

if ($fatalError === '' && looksAuthenticated((string) ($step3['body'] ?? '')) && $equipmentSellRequested) {
	$auctionItemIdRaw = trim((string) ($_POST['equipment_auction_item_id'] ?? ''));
	$auctionPriceRaw = trim((string) ($_POST['equipment_auction_price'] ?? '0.01'));
	$auctionLengthRaw = trim((string) ($_POST['equipment_auction_length'] ?? '24'));
	$auctionPageRaw = trim((string) ($_POST['equipment_page'] ?? '1'));
	$auctionEqId = preg_replace('/\D+/', '', $auctionItemIdRaw);
	$auctionPrice = preg_replace('/[^0-9.]/', '', str_replace(',', '.', $auctionPriceRaw));
	$auctionLength = preg_replace('/\D+/', '', $auctionLengthRaw);
	$auctionPage = preg_replace('/\D+/', '', $auctionPageRaw);

	$hasValidSaleData = preg_match('/^\d+$/', (string) $auctionEqId) === 1
		&& preg_match('/^\d+(?:\.\d{1,4})?$/', (string) $auctionPrice) === 1
		&& preg_match('/^\d+$/', (string) $auctionLength) === 1;

	$auctionParams = [
		'action' => 'CREATE_AUCTION',
		'price' => $auctionPrice !== '' ? $auctionPrice : '0.01',
		'id' => 'EQUIPMENT ' . (string) $auctionEqId,
		'length' => $auctionLength !== '' ? $auctionLength : '24',
		'eqStorageList' => 'true',
		'page' => $auctionPage !== '' ? $auctionPage : '1',
	];
	$auctionUrl = serverUrl('auctionAction.html?') . http_build_query($auctionParams);

	$equipmentSellResult = [
		'attempted' => true,
		'saved' => false,
		'reason' => $hasValidSaleData ? 'equipment-auction-request-error' : 'equipment-auction-invalid-data',
		'httpStatus' => 0,
		'url' => $auctionUrl,
		'itemId' => (string) $auctionEqId,
		'price' => (string) $auctionParams['price'],
		'length' => (string) $auctionParams['length'],
		'responseSnippet' => '',
		'error' => '',
	];

	if ($hasValidSaleData) {
		$auctionStep = curlRequest($ch, $auctionUrl, [
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => '',
			CURLOPT_HTTPHEADER => $headers,
		]);
		$auctionBody = (string) ($auctionStep['body'] ?? '');
		$snippet = compactNodeText(substr($auctionBody, 0, 240));
		$looksUpdated = str_contains($auctionBody, 'equipmentInStorage') || str_contains($auctionBody, 'equipmentTable') || str_contains($auctionBody, 'myEquipment');
		$looksError = preg_match('/\b(error|failed|forbidden|invalid|not\s+enough|cannot|can\'t)\b/i', $auctionBody) === 1;
		$auctionOk = $auctionStep['errno'] === 0
			&& (int) ($auctionStep['statusCode'] ?? 0) >= 200
			&& (int) ($auctionStep['statusCode'] ?? 0) < 400
			&& ($looksUpdated || !$looksError);

		$equipmentSellResult = [
			'attempted' => true,
			'saved' => $auctionOk,
			'reason' => $auctionOk
				? 'equipment-auction-created'
				: ($auctionStep['errno'] !== 0 ? 'equipment-auction-request-error' : 'equipment-auction-rejected'),
			'httpStatus' => (int) ($auctionStep['statusCode'] ?? 0),
			'url' => (string) ($auctionStep['effectiveUrl'] ?: $auctionUrl),
			'itemId' => (string) $auctionEqId,
			'price' => (string) $auctionParams['price'],
			'length' => (string) $auctionParams['length'],
			'responseSnippet' => $snippet,
			'error' => (string) ($auctionStep['error'] ?? ''),
		];
	}
}

if ($fatalError === '' && looksAuthenticated((string) ($step3['body'] ?? '')) && ($equipmentLoadRequested || $equipmentSellRequested)) {
	$storageEquipmentStep = curlRequest($ch, $storageEquipmentUrl, [
		CURLOPT_POST => false,
		CURLOPT_HTTPGET => true,
		CURLOPT_HTTPHEADER => $headers,
	]);
	$storageEquipmentListStep = curlRequest($ch, $storageEquipmentListUrl, [
		CURLOPT_POST => false,
		CURLOPT_HTTPGET => true,
		CURLOPT_HTTPHEADER => $headers,
	]);

	$storageEquipmentBody = (string) ($storageEquipmentStep['body'] ?? '');
	$storageEquipmentListBody = (string) ($storageEquipmentListStep['body'] ?? '');
	$storageEquipmentBodyLength = strlen($storageEquipmentBody);
	$storageEquipmentListBodyLength = strlen($storageEquipmentListBody);
	$storageEquipmentOk = $storageEquipmentStep['errno'] === 0
		&& (int) ($storageEquipmentStep['statusCode'] ?? 0) >= 200
		&& (int) ($storageEquipmentStep['statusCode'] ?? 0) < 400
		&& trim($storageEquipmentBody) !== '';
	$storageEquipmentListOk = $storageEquipmentListStep['errno'] === 0
		&& (int) ($storageEquipmentListStep['statusCode'] ?? 0) >= 200
		&& (int) ($storageEquipmentListStep['statusCode'] ?? 0) < 400
		&& trim($storageEquipmentListBody) !== '';

	$equipmentInventory = $storageEquipmentOk
		? extractStorageEquipmentInventoryFromHtml($storageEquipmentBody)
		: ['equipped' => [], 'storage' => []];
	$equipmentInventoryList = $storageEquipmentListOk
		? extractStorageEquipmentInventoryFromHtml($storageEquipmentListBody)
		: ['equipped' => [], 'storage' => []];
	$equippedItems = is_array($equipmentInventory['equipped'] ?? null) ? (array) $equipmentInventory['equipped'] : [];
	$storageItems = is_array($equipmentInventory['storage'] ?? null) ? (array) $equipmentInventory['storage'] : [];
	$listEquippedItems = is_array($equipmentInventoryList['equipped'] ?? null) ? (array) $equipmentInventoryList['equipped'] : [];
	$listStorageItems = is_array($equipmentInventoryList['storage'] ?? null) ? (array) $equipmentInventoryList['storage'] : [];

	$equippedById = [];
	foreach ($equippedItems as $eqItem) {
		$eqId = trim((string) ($eqItem['id'] ?? ''));
		if ($eqId !== '') {
			$equippedById[$eqId] = $eqItem;
		}
	}
	foreach ($listEquippedItems as $eqItem) {
		$eqId = trim((string) ($eqItem['id'] ?? ''));
		if ($eqId !== '') {
			$equippedById[$eqId] = $eqItem;
		}
	}

	$storageById = [];
	foreach ($storageItems as $eqItem) {
		$eqId = trim((string) ($eqItem['id'] ?? ''));
		if ($eqId !== '') {
			$storageById[$eqId] = $eqItem;
		}
	}
	foreach ($listStorageItems as $eqItem) {
		$eqId = trim((string) ($eqItem['id'] ?? ''));
		if ($eqId !== '') {
			$storageById[$eqId] = $eqItem;
		}
	}

	$equippedItems = array_values($equippedById);
	$storageItems = array_values($storageById);

	$storageEquipmentCombinedOk = $storageEquipmentOk || $storageEquipmentListOk;

	$storageEquipmentResult = [
		'attempted' => true,
		'saved' => $storageEquipmentCombinedOk,
		'reason' => $storageEquipmentCombinedOk
			? ((count($equippedItems) + count($storageItems)) > 0 ? 'storage-equipment-loaded' : 'storage-equipment-empty')
			: (($storageEquipmentStep['errno'] !== 0 || $storageEquipmentListStep['errno'] !== 0) ? 'storage-equipment-request-error' : 'storage-equipment-http-error'),
		'httpStatus' => (int) ($storageEquipmentStep['statusCode'] ?? 0),
		'url' => (string) ($storageEquipmentStep['effectiveUrl'] ?: $storageEquipmentUrl),
		'inventoryUrl' => (string) ($storageEquipmentListStep['effectiveUrl'] ?: $storageEquipmentListUrl),
		'inventoryHttpStatus' => (int) ($storageEquipmentListStep['statusCode'] ?? 0),
		'bodyLength' => $storageEquipmentBodyLength + $storageEquipmentListBodyLength,
		'equipped' => $equippedItems,
		'storage' => $storageItems,
		'equippedCount' => count($equippedItems),
		'storageCount' => count($storageItems),
		'error' => trim((string) ($storageEquipmentStep['error'] ?? '')) !== ''
			? (string) ($storageEquipmentStep['error'] ?? '')
			: (string) ($storageEquipmentListStep['error'] ?? ''),
	];
}

if ($fatalError === '' && looksAuthenticated((string) ($step3['body'] ?? '')) && $auctionBidRequested) {
	$auctionId = preg_replace('/\D+/', '', trim((string) ($_POST['auction_id'] ?? '')));
	$auctionPriceRaw = trim((string) ($_POST['auction_bid_price'] ?? ''));
	$auctionPrice = preg_replace('/[^0-9.]/', '', str_replace(',', '.', $auctionPriceRaw));
	$auctionReferer = trim((string) ($_POST['auction_market_url'] ?? $auctionsUrl));

	$auctionBidResult = submitAuctionBid(
		$ch,
		$auctionReferer,
		$headers,
		[
			'auctionId' => $auctionId,
			'price' => $auctionPrice,
		]
	);
}

if ($fatalError === '' && looksAuthenticated((string) ($step3['body'] ?? '')) && ($auctionMarketLoadRequested || $auctionBidRequested)) {
	$auctionsStep = curlRequest($ch, $auctionsUrl, [
		CURLOPT_POST => false,
		CURLOPT_HTTPGET => true,
		CURLOPT_HTTPHEADER => $headers,
	]);

	$auctionsBody = (string) ($auctionsStep['body'] ?? '');
	$auctionsBodyLength = strlen($auctionsBody);
	$auctionsOk = $auctionsStep['errno'] === 0
		&& (int) ($auctionsStep['statusCode'] ?? 0) >= 200
		&& (int) ($auctionsStep['statusCode'] ?? 0) < 400
		&& trim($auctionsBody) !== '';
	$auctionOffers = $auctionsOk ? extractAuctionOffersFromHtml($auctionsBody) : [];

	$auctionMarketResult = [
		'attempted' => true,
		'saved' => $auctionsOk,
		'reason' => $auctionsOk
			? (count($auctionOffers) > 0 ? 'auctions-loaded' : 'auctions-empty')
			: ($auctionsStep['errno'] !== 0 ? 'auctions-request-error' : 'auctions-http-error'),
		'httpStatus' => (int) ($auctionsStep['statusCode'] ?? 0),
		'url' => (string) ($auctionsStep['effectiveUrl'] ?: $auctionsUrl),
		'bodyLength' => $auctionsBodyLength,
		'itemsCount' => count($auctionOffers),
		'offers' => $auctionOffers,
		'error' => (string) ($auctionsStep['error'] ?? ''),
	];
}

if ($fatalError === '' && looksAuthenticated((string) ($step3['body'] ?? '')) && $articleInspectRequested) {
	$articleUrlRaw = trim((string) ($_POST['article_url'] ?? ''));
	$articleUrl = normalizeArticlePageUrl($articleUrlRaw, (string) ($step3['effectiveUrl'] ?? serverUrl('index.html')));

	$articleInspectResult = [
		'attempted' => true,
		'saved' => false,
		'reason' => $articleUrl === '' ? 'article-url-invalid' : 'article-inspect-request-error',
		'httpStatus' => 0,
		'url' => $articleUrl,
		'articleId' => '',
		'articleTitle' => '',
		'voteDetected' => false,
		'subscribeDetected' => false,
		'voteActionUrl' => '',
		'subscribeActionUrl' => '',
		'responseSnippet' => '',
		'error' => '',
	];

	if ($articleUrl !== '') {
		$articleStep = curlRequest($ch, $articleUrl, [
			CURLOPT_POST => false,
			CURLOPT_HTTPGET => true,
			CURLOPT_HTTPHEADER => $headers,
		]);

		$articleBody = (string) ($articleStep['body'] ?? '');
		$articleHttpOk = $articleStep['errno'] === 0
			&& (int) ($articleStep['statusCode'] ?? 0) >= 200
			&& (int) ($articleStep['statusCode'] ?? 0) < 400
			&& trim($articleBody) !== '';
		$articleInfo = $articleHttpOk
			? extractArticleActionsFromHtml($articleBody, (string) ($articleStep['effectiveUrl'] ?: $articleUrl))
			: [
				'articleId' => '',
				'articleTitle' => '',
				'voteDetected' => false,
				'subscribeDetected' => false,
				'voteActionUrl' => '',
				'subscribeActionUrl' => '',
			];

		$articleInspectResult = [
			'attempted' => true,
			'saved' => $articleHttpOk,
			'reason' => !$articleHttpOk
				? ((int) ($articleStep['errno'] ?? 0) !== 0 ? 'article-inspect-request-error' : 'article-inspect-http-error')
				: ((!empty($articleInfo['voteDetected']) || !empty($articleInfo['subscribeDetected'])) ? 'article-controls-found' : 'article-controls-not-found'),
			'httpStatus' => (int) ($articleStep['statusCode'] ?? 0),
			'url' => (string) ($articleStep['effectiveUrl'] ?: $articleUrl),
			'articleId' => (string) ($articleInfo['articleId'] ?? ''),
			'articleTitle' => (string) ($articleInfo['articleTitle'] ?? ''),
			'voteDetected' => !empty($articleInfo['voteDetected']),
			'subscribeDetected' => !empty($articleInfo['subscribeDetected']),
			'voteActionUrl' => (string) ($articleInfo['voteActionUrl'] ?? ''),
			'subscribeActionUrl' => (string) ($articleInfo['subscribeActionUrl'] ?? ''),
			'responseSnippet' => trim(substr(compactNodeText($articleBody), 0, 280)),
			'error' => (string) ($articleStep['error'] ?? ''),
		];
	}
}

if ($fatalError === '' && looksAuthenticated((string) ($step3['body'] ?? '')) && $electionsInspectRequested) {
	$electionsUrlRaw = trim((string) ($_POST['elections_url'] ?? ''));
	$electionsUrl = normalizeElectionsPageUrl($electionsUrlRaw, (string) ($step3['effectiveUrl'] ?? serverUrl('index.html')));

	$electionsInspectResult = [
		'attempted' => true,
		'saved' => false,
		'reason' => $electionsUrl === '' ? 'elections-url-invalid' : 'elections-inspect-request-error',
		'httpStatus' => 0,
		'url' => $electionsUrl,
		'pageTitle' => '',
		'candidateActionUrl' => '',
		'options' => [],
		'responseSnippet' => '',
		'responseHtml' => '',
		'error' => '',
	];

	if ($electionsUrl !== '') {
		$electionsStep = curlRequest($ch, $electionsUrl, [
			CURLOPT_POST => false,
			CURLOPT_HTTPGET => true,
			CURLOPT_HTTPHEADER => $headers,
		]);

		$electionsBody = (string) ($electionsStep['body'] ?? '');
		$electionsHttpOk = $electionsStep['errno'] === 0
			&& (int) ($electionsStep['statusCode'] ?? 0) >= 200
			&& (int) ($electionsStep['statusCode'] ?? 0) < 400
			&& trim($electionsBody) !== '';

		$electionsInspectResult = [
			'attempted' => true,
			'saved' => $electionsHttpOk,
			'reason' => !$electionsHttpOk
				? ((int) ($electionsStep['errno'] ?? 0) !== 0 ? 'elections-inspect-request-error' : 'elections-inspect-http-error')
				: 'elections-inspected',
			'httpStatus' => (int) ($electionsStep['statusCode'] ?? 0),
			'url' => (string) ($electionsStep['effectiveUrl'] ?: $electionsUrl),
			'pageTitle' => extractPagePrimaryTitle($electionsBody),
			'candidateActionUrl' => $electionsHttpOk ? extractElectionsCongressCandidateActionUrl($electionsBody, (string) ($electionsStep['effectiveUrl'] ?: $electionsUrl)) : '',
			'options' => $electionsHttpOk ? extractPageActionOptionsFromHtml($electionsBody, (string) ($electionsStep['effectiveUrl'] ?: $electionsUrl)) : [],
			'responseSnippet' => trim(substr(compactNodeText($electionsBody), 0, 280)),
			'responseHtml' => $electionsBody,
			'error' => (string) ($electionsStep['error'] ?? ''),
		];
	}
}

if ($fatalError === '' && looksAuthenticated((string) ($step3['body'] ?? '')) && $electionsCandidateRequested) {
	$electionsUrl = normalizeElectionsPageUrl(trim((string) ($_POST['elections_url'] ?? '')), (string) ($step3['effectiveUrl'] ?? serverUrl('index.html')));
	$candidateActionUrlRaw = trim((string) ($_POST['elections_candidate_action_url'] ?? ''));
	$candidateActionUrl = $candidateActionUrlRaw !== ''
		? resolveUrl($electionsUrl !== '' ? $electionsUrl : (string) ($step3['effectiveUrl'] ?? serverUrl('index.html')), $candidateActionUrlRaw)
		: resolveUrl($electionsUrl !== '' ? $electionsUrl : (string) ($step3['effectiveUrl'] ?? serverUrl('index.html')), 'congressElectionsCandidate');
	$presentation = trim((string) ($_POST['elections_presentation'] ?? 'http://'));
	if ($presentation === '') {
		$presentation = 'http://';
	}

	$candidatePayload = ['presentation' => $presentation];
	$candidateHeaders = array_merge($headers, [
		'Origin: https://' . $server . '.e-sim.org',
		'Referer: ' . ($electionsUrl !== '' ? $electionsUrl : (string) ($step3['effectiveUrl'] ?? serverUrl('index.html'))),
		'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
		'Accept: application/json, text/plain, */*',
		'X-Requested-With: XMLHttpRequest',
	]);

	$candidateStep = curlRequest($ch, $candidateActionUrl, [
		CURLOPT_POST => true,
		CURLOPT_HTTPGET => false,
		CURLOPT_POSTFIELDS => http_build_query($candidatePayload),
		CURLOPT_HTTPHEADER => $candidateHeaders,
	]);

	$candidateBody = (string) ($candidateStep['body'] ?? '');
	$candidateHttpOk = $candidateStep['errno'] === 0
		&& (int) ($candidateStep['statusCode'] ?? 0) >= 200
		&& (int) ($candidateStep['statusCode'] ?? 0) < 400;
	$candidateJsonStatus = '';
	$candidateJsonError = '';
	$candidateDecoded = json_decode($candidateBody, true);
	if (is_array($candidateDecoded)) {
		$candidateJsonStatus = strtoupper(trim((string) ($candidateDecoded['status'] ?? '')));
		$candidateJsonError = trim((string) ($candidateDecoded['error'] ?? ($candidateDecoded['message'] ?? '')));
	}

	$electionsCandidateResult = [
		'attempted' => true,
		'saved' => $candidateHttpOk && ($candidateJsonStatus === '' || $candidateJsonStatus === 'OK'),
		'reason' => !$candidateHttpOk
			? ((int) ($candidateStep['errno'] ?? 0) !== 0 ? 'elections-candidate-request-error' : 'elections-candidate-http-error')
			: (($candidateJsonStatus === 'OK' || $candidateJsonStatus === '') ? 'elections-candidate-submitted' : 'elections-candidate-rejected'),
		'httpStatus' => (int) ($candidateStep['statusCode'] ?? 0),
		'url' => (string) ($candidateStep['effectiveUrl'] ?: $candidateActionUrl),
		'presentation' => $presentation,
		'requestPayload' => http_build_query($candidatePayload),
		'responseSnippet' => trim(substr(compactNodeText($candidateBody), 0, 280)),
		'responseHtml' => $candidateBody,
		'error' => $candidateJsonError !== '' ? $candidateJsonError : (string) ($candidateStep['error'] ?? ''),
	];
}

if ($fatalError === '' && looksAuthenticated((string) ($step3['body'] ?? '')) && $militaryUnitInspectRequested) {
	$militaryUnitUrlRaw = trim((string) ($_POST['military_unit_url'] ?? ''));
	$militaryUnitUrl = normalizeMilitaryUnitPageUrl($militaryUnitUrlRaw, (string) ($step3['effectiveUrl'] ?? serverUrl('index.html')));

	$militaryUnitInspectResult = [
		'attempted' => true,
		'saved' => false,
		'reason' => $militaryUnitUrl === '' ? 'military-unit-url-invalid' : 'military-unit-inspect-request-error',
		'httpStatus' => 0,
		'url' => $militaryUnitUrl,
		'unitName' => '',
		'applyDetected' => false,
		'applyActionUrl' => '',
		'applyMethod' => 'POST',
		'applyFields' => [],
		'applyDefaultMessage' => '',
		'options' => [],
		'responseSnippet' => '',
		'responseHtml' => '',
		'error' => '',
	];

	if ($militaryUnitUrl !== '') {
		$militaryUnitStep = curlRequest($ch, $militaryUnitUrl, [
			CURLOPT_POST => false,
			CURLOPT_HTTPGET => true,
			CURLOPT_HTTPHEADER => $headers,
		]);

		$militaryUnitBody = (string) ($militaryUnitStep['body'] ?? '');
		$militaryUnitHttpOk = $militaryUnitStep['errno'] === 0
			&& (int) ($militaryUnitStep['statusCode'] ?? 0) >= 200
			&& (int) ($militaryUnitStep['statusCode'] ?? 0) < 400
			&& trim($militaryUnitBody) !== '';
		$militaryApplyInfo = $militaryUnitHttpOk
			? extractMilitaryUnitApplyFormFromHtml($militaryUnitBody, (string) ($militaryUnitStep['effectiveUrl'] ?: $militaryUnitUrl))
			: [
				'applyDetected' => false,
				'applyActionUrl' => '',
				'applyMethod' => 'POST',
				'applyFields' => [],
				'applyDefaultMessage' => '',
			];

		$militaryUnitInspectResult = [
			'attempted' => true,
			'saved' => $militaryUnitHttpOk,
			'reason' => !$militaryUnitHttpOk
				? ((int) ($militaryUnitStep['errno'] ?? 0) !== 0 ? 'military-unit-inspect-request-error' : 'military-unit-inspect-http-error')
				: 'military-unit-inspected',
			'httpStatus' => (int) ($militaryUnitStep['statusCode'] ?? 0),
			'url' => (string) ($militaryUnitStep['effectiveUrl'] ?: $militaryUnitUrl),
			'unitName' => extractPagePrimaryTitle($militaryUnitBody),
			'applyDetected' => !empty($militaryApplyInfo['applyDetected']),
			'applyActionUrl' => (string) ($militaryApplyInfo['applyActionUrl'] ?? ''),
			'applyMethod' => (string) ($militaryApplyInfo['applyMethod'] ?? 'POST'),
			'applyFields' => is_array($militaryApplyInfo['applyFields'] ?? null) ? (array) $militaryApplyInfo['applyFields'] : [],
			'applyDefaultMessage' => (string) ($militaryApplyInfo['applyDefaultMessage'] ?? ''),
			'options' => $militaryUnitHttpOk ? extractPageActionOptionsFromHtml($militaryUnitBody, (string) ($militaryUnitStep['effectiveUrl'] ?: $militaryUnitUrl)) : [],
			'responseSnippet' => trim(substr(compactNodeText($militaryUnitBody), 0, 280)),
			'responseHtml' => $militaryUnitBody,
			'error' => (string) ($militaryUnitStep['error'] ?? ''),
		];
	}
}

if ($fatalError === '' && looksAuthenticated((string) ($step3['body'] ?? '')) && $militaryUnitApplyRequested) {
	$militaryUnitUrl = normalizeMilitaryUnitPageUrl(trim((string) ($_POST['military_unit_url'] ?? '')), (string) ($step3['effectiveUrl'] ?? serverUrl('index.html')));
	$applyActionUrlRaw = trim((string) ($_POST['military_unit_apply_action_url'] ?? ''));
	$applyMethod = strtoupper(trim((string) ($_POST['military_unit_apply_method'] ?? 'POST')));
	$applyFieldsEncoded = trim((string) ($_POST['military_unit_apply_fields_encoded'] ?? ''));
	$applyFieldsDecoded = $applyFieldsEncoded !== '' ? base64_decode($applyFieldsEncoded, true) : '';
	$applyFields = is_string($applyFieldsDecoded) ? json_decode($applyFieldsDecoded, true) : [];
	if (!is_array($applyFields)) {
		$applyFields = [];
	}

	$applyMessage = trim((string) ($_POST['military_unit_apply_message'] ?? (string) ($applyFields['message'] ?? '')));
	if ($applyMessage !== '') {
		$applyFields['message'] = $applyMessage;
	}
	if (!isset($applyFields['action']) || strtoupper(trim((string) $applyFields['action'])) !== 'SEND_APPLICATION') {
		$applyFields['action'] = 'SEND_APPLICATION';
	}

	$applyActionUrl = $applyActionUrlRaw !== ''
		? resolveUrl($militaryUnitUrl !== '' ? $militaryUnitUrl : (string) ($step3['effectiveUrl'] ?? serverUrl('index.html')), $applyActionUrlRaw)
		: resolveUrl($militaryUnitUrl !== '' ? $militaryUnitUrl : (string) ($step3['effectiveUrl'] ?? serverUrl('index.html')), 'militaryUnitsActions.html');

	if ($applyMethod !== 'POST' && $applyMethod !== 'GET') {
		$applyMethod = 'POST';
	}

	$applyHeaders = array_merge($headers, [
		'Origin: ' . rtrim(serverUrl(''), '/'),
		'Referer: ' . ($militaryUnitUrl !== '' ? $militaryUnitUrl : (string) ($step3['effectiveUrl'] ?? serverUrl('index.html'))),
		'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
	]);

	if ($applyMethod === 'GET') {
		$applyUrlWithParams = $applyActionUrl;
		if ($applyFields !== []) {
			$applyUrlWithParams .= (str_contains($applyUrlWithParams, '?') ? '&' : '?') . http_build_query($applyFields);
		}
		$applyStep = curlRequest($ch, $applyUrlWithParams, [
			CURLOPT_POST => false,
			CURLOPT_HTTPGET => true,
			CURLOPT_HTTPHEADER => $applyHeaders,
		]);
	} else {
		$applyStep = curlRequest($ch, $applyActionUrl, [
			CURLOPT_POST => true,
			CURLOPT_HTTPGET => false,
			CURLOPT_POSTFIELDS => http_build_query($applyFields),
			CURLOPT_HTTPHEADER => $applyHeaders,
		]);
	}

	$applyBody = (string) ($applyStep['body'] ?? '');
	$applyHttpOk = $applyStep['errno'] === 0
		&& (int) ($applyStep['statusCode'] ?? 0) >= 200
		&& (int) ($applyStep['statusCode'] ?? 0) < 400;
	$applyLooksSuccess = preg_match('/(application sent|request sent|success|accepted|submitted)/i', $applyBody) === 1;
	$applyLooksError = preg_match('/(error|failed|forbidden|denied|cannot|already applied|not logged)/i', $applyBody) === 1;

	$militaryUnitApplyResult = [
		'attempted' => true,
		'saved' => $applyHttpOk && ($applyLooksSuccess || (!$applyLooksError && trim($applyBody) !== '')),
		'reason' => !$applyHttpOk
			? ((int) ($applyStep['errno'] ?? 0) !== 0 ? 'military-unit-apply-request-error' : 'military-unit-apply-http-error')
			: ($applyLooksSuccess ? 'military-unit-apply-submitted' : ($applyLooksError ? 'military-unit-apply-rejected' : 'military-unit-apply-processed')),
		'httpStatus' => (int) ($applyStep['statusCode'] ?? 0),
		'url' => (string) ($applyStep['effectiveUrl'] ?: $applyActionUrl),
		'unitId' => trim((string) ($applyFields['id'] ?? '')),
		'message' => trim((string) ($applyFields['message'] ?? '')),
		'requestPayload' => $applyFields !== [] ? http_build_query($applyFields) : '',
		'responseSnippet' => trim(substr(compactNodeText($applyBody), 0, 280)),
		'responseHtml' => $applyBody,
		'error' => (string) ($applyStep['error'] ?? ''),
	];
}

if ($fatalError === '' && looksAuthenticated((string) ($step3['body'] ?? '')) && $articleVoteRequested) {
	$articleId = preg_replace('/\D+/', '', trim((string) ($_POST['article_id'] ?? '')));
	$articleUrl = normalizeArticlePageUrl(trim((string) ($_POST['article_url'] ?? '')), (string) ($step3['effectiveUrl'] ?? serverUrl('index.html')));
	$voteActionUrl = trim((string) ($_POST['article_vote_action_url'] ?? ''));

	$articleVoteResult = submitArticleVote($ch, $articleUrl, $headers, [
		'articleId' => $articleId,
		'voteActionUrl' => $voteActionUrl,
	]);
}

if ($fatalError === '' && looksAuthenticated((string) ($step3['body'] ?? '')) && $articleSubscribeRequested) {
	$articleId = preg_replace('/\D+/', '', trim((string) ($_POST['article_id'] ?? '')));
	$articleUrl = normalizeArticlePageUrl(trim((string) ($_POST['article_url'] ?? '')), (string) ($step3['effectiveUrl'] ?? serverUrl('index.html')));
	$subscribeActionUrl = trim((string) ($_POST['article_subscribe_action_url'] ?? ''));

	$articleSubscribeResult = submitArticleSubscribe($ch, $articleUrl, $headers, [
		'articleId' => $articleId,
		'subscribeActionUrl' => $subscribeActionUrl,
	]);
}

if ($fatalError === '' && $banditBlueOpenRequested) {
	$banditBlueOpenResult = openBanditBlueGame($ch, (string) ($step3['effectiveUrl'] ?: $baseUrl), $headers, $gameRoomUrl);
}

if ($fatalError === '' && $banditBlueRunRequested) {
	$banditBlueRunResult = runBanditBlueRound($ch, (string) ($step3['effectiveUrl'] ?: $baseUrl), $headers, $gameRoomUrl);
}

if ($fatalError === '' && $tutorialMissionCompleteRequested) {
	$tutorialStateBeforeSubmit = extractTutorialMissionStateFromHtml(
		(string) ($step3['body'] ?? ''),
		(string) (($step3['effectiveUrl'] ?? '') !== '' ? $step3['effectiveUrl'] : serverUrl('index.html'))
	);
	$completeUrlPosted = trim((string) ($_POST['tutorial_complete_url'] ?? ''));
	$completeUrlDetected = trim((string) ($tutorialStateBeforeSubmit['rewardActionUrl'] ?? ''));
	$completeMethodDetected = trim((string) ($tutorialStateBeforeSubmit['rewardMethod'] ?? 'POST'));
	$completeFieldsDetected = is_array($tutorialStateBeforeSubmit['rewardFields'] ?? null)
		? (array) $tutorialStateBeforeSubmit['rewardFields']
		: [];

	$completeUrl = $completeUrlPosted !== ''
		? $completeUrlPosted
		: ($completeUrlDetected !== '' ? $completeUrlDetected : serverUrl('betaMissions.html'));

	$tutorialMissionCompleteResult = submitTutorialMissionComplete(
		$ch,
		(string) ($step3['effectiveUrl'] ?: $baseUrl),
		$headers,
		$completeUrl,
		$completeMethodDetected,
		$completeFieldsDetected
	);

	$step3 = curlRequest($ch, $baseUrl, [
		CURLOPT_POST => false,
		CURLOPT_HTTPGET => true,
		CURLOPT_HTTPHEADER => $headers,
	]);

	if ($step3['errno'] !== 0) {
		$fatalError = 'GET post-tutorial-mission-complete fallo: ' . $step3['error'];
	}
}

if ($fatalError === '' && $tutorialMissionSkipRequested) {
	$tutorialStateBeforeSkip = extractTutorialMissionStateFromHtml(
		(string) ($step3['body'] ?? ''),
		(string) (($step3['effectiveUrl'] ?? '') !== '' ? $step3['effectiveUrl'] : serverUrl('index.html'))
	);
	$skipUrlPosted = trim((string) ($_POST['tutorial_skip_url'] ?? ''));
	$skipUrlDetected = trim((string) ($tutorialStateBeforeSkip['skipActionUrl'] ?? ''));
	$skipMethodDetected = trim((string) ($tutorialStateBeforeSkip['skipMethod'] ?? 'POST'));
	$skipFieldsDetected = is_array($tutorialStateBeforeSkip['skipFields'] ?? null)
		? (array) $tutorialStateBeforeSkip['skipFields']
		: [];

	$skipUrl = $skipUrlPosted !== ''
		? $skipUrlPosted
		: ($skipUrlDetected !== '' ? $skipUrlDetected : serverUrl('betaMissions.html'));

	$tutorialMissionSkipResult = submitTutorialMissionSkip(
		$ch,
		(string) ($step3['effectiveUrl'] ?: $baseUrl),
		$headers,
		$skipUrl,
		$skipMethodDetected,
		$skipFieldsDetected
	);

	$step3 = curlRequest($ch, $baseUrl, [
		CURLOPT_POST => false,
		CURLOPT_HTTPGET => true,
		CURLOPT_HTTPHEADER => $headers,
	]);

	if ($step3['errno'] !== 0) {
		$fatalError = 'GET post-tutorial-mission-skip fallo: ' . $step3['error'];
	}
}

if ($fatalError === '' && $freeStarterPackOpenRequested) {
	$promoUrlPosted = trim((string) ($_POST['free_starter_pack_open_url'] ?? ''));
	$promoUrl = $promoUrlPosted !== ''
		? $promoUrlPosted
		: serverUrl('shop.html?shopType=PROMOTIONS');

	$promoStep = curlRequest($ch, $promoUrl, [
		CURLOPT_POST => false,
		CURLOPT_HTTPGET => true,
		CURLOPT_HTTPHEADER => $headers,
	]);

	$promoBody = (string) ($promoStep['body'] ?? '');
	$promoOk = $promoStep['errno'] === 0
		&& (int) ($promoStep['statusCode'] ?? 0) >= 200
		&& (int) ($promoStep['statusCode'] ?? 0) < 400
		&& trim($promoBody) !== '';

	$freeStarterPackOpenResult = [
		'attempted' => true,
		'saved' => $promoOk,
		'reason' => $promoOk
			? 'free-starter-pack-promotions-loaded'
			: ($promoStep['errno'] !== 0 ? 'free-starter-pack-promotions-request-error' : 'free-starter-pack-promotions-http-error'),
		'httpStatus' => (int) ($promoStep['statusCode'] ?? 0),
		'url' => (string) ($promoStep['effectiveUrl'] ?: $promoUrl),
		'bodyLength' => strlen($promoBody),
		'found' => false,
		'claimButtonFound' => false,
		'claimUrl' => '',
		'error' => (string) ($promoStep['error'] ?? ''),
	];

	if ($promoOk) {
		$promoDetect = detectFreeStarterPackFromHtml($promoBody, (string) ($promoStep['effectiveUrl'] ?: $promoUrl));
		$freeStarterPackOpenResult['found'] = !empty($promoDetect['found']);
		$freeStarterPackOpenResult['claimButtonFound'] = !empty($promoDetect['claimButtonFound']);
		$freeStarterPackOpenResult['claimUrl'] = trim((string) ($promoDetect['claimUrl'] ?? ''));
		$freeStarterPackOpenResult['reason'] = !empty($promoDetect['found'])
			? 'free-starter-pack-detected-in-promotions'
			: 'free-starter-pack-not-detected-in-promotions';
		$freeStarterPackResult = $promoDetect;
	}
}

if ($fatalError === '' && $freeStarterPackClaimRequested) {
	$claimUrlPosted = trim((string) ($_POST['free_starter_pack_claim_url'] ?? ''));
	$promoUrl = serverUrl('shop.html?shopType=PROMOTIONS');
	$claimUrl = $claimUrlPosted;
	$claimRefererUrl = $promoUrl;

	$freeStarterPackClaimResult = [
		'attempted' => true,
		'saved' => false,
		'reason' => 'free-starter-pack-claim-url-missing',
		'httpStatus' => 0,
		'url' => '',
		'claimUrl' => '',
		'responseSnippet' => '',
		'error' => '',
	];

	if ($claimUrl === '') {
		$promoStep = curlRequest($ch, $promoUrl, [
			CURLOPT_POST => false,
			CURLOPT_HTTPGET => true,
			CURLOPT_HTTPHEADER => $headers,
		]);

		$promoBody = (string) ($promoStep['body'] ?? '');
		$promoOk = $promoStep['errno'] === 0
			&& (int) ($promoStep['statusCode'] ?? 0) >= 200
			&& (int) ($promoStep['statusCode'] ?? 0) < 400
			&& trim($promoBody) !== '';

		if ($promoOk) {
			$promoEffectiveUrl = (string) ($promoStep['effectiveUrl'] ?: $promoUrl);
			$promoDetect = detectFreeStarterPackFromHtml($promoBody, $promoEffectiveUrl);
			$claimUrl = trim((string) ($promoDetect['claimUrl'] ?? ''));
			$claimRefererUrl = $promoEffectiveUrl;
			if ($claimUrl === '') {
				if (!empty($promoDetect['found']) || !empty($promoDetect['claimButtonFound'])) {
					$claimUrl = $promoEffectiveUrl;
					$freeStarterPackClaimResult['reason'] = 'free-starter-pack-claim-url-fallback-promotions';
				} else {
					$freeStarterPackClaimResult['reason'] = 'free-starter-pack-claim-url-not-detected';
				}
			}
		} else {
			$freeStarterPackClaimResult['reason'] = $promoStep['errno'] !== 0
				? 'free-starter-pack-promotions-request-error'
				: 'free-starter-pack-promotions-http-error';
			$freeStarterPackClaimResult['httpStatus'] = (int) ($promoStep['statusCode'] ?? 0);
			$freeStarterPackClaimResult['url'] = (string) ($promoStep['effectiveUrl'] ?: $promoUrl);
			$freeStarterPackClaimResult['error'] = (string) ($promoStep['error'] ?? '');
		}
	}

	if ($claimUrl !== '') {
		$freeStarterPackClaimResult = submitFreeStarterPackClaim($ch, $claimRefererUrl, $headers, $claimUrl);
	}
}

if ($fatalError === '' && $productMarketRequested) {
	$productMarketStep = curlRequest($ch, $productMarketUrl, [
		CURLOPT_POST => false,
		CURLOPT_HTTPGET => true,
		CURLOPT_HTTPHEADER => $headers,
	]);

	$productMarketBody = (string) ($productMarketStep['body'] ?? '');
	$productMarketResult = [
		'attempted' => true,
		'saved' => $productMarketStep['errno'] === 0
			&& (int) ($productMarketStep['statusCode'] ?? 0) >= 200
			&& (int) ($productMarketStep['statusCode'] ?? 0) < 400
			&& trim($productMarketBody) !== '',
		'reason' => $productMarketStep['errno'] !== 0
			? 'product-market-request-error'
			: ((int) ($productMarketStep['statusCode'] ?? 0) >= 200 && (int) ($productMarketStep['statusCode'] ?? 0) < 400 ? 'product-market-loaded' : 'product-market-http-error'),
		'httpStatus' => (int) ($productMarketStep['statusCode'] ?? 0),
		'url' => (string) ($productMarketStep['effectiveUrl'] ?: $productMarketUrl),
		'bodyLength' => strlen($productMarketBody),
		'error' => (string) ($productMarketStep['error'] ?? ''),
	];
}

if ($fatalError === '' && $productMarketBuyRequested) {
	$buyOfferId = trim((string) ($_POST['product_market_buy_offer_id'] ?? ''));
	$buyCurrencyId = trim((string) ($_POST['product_market_buy_currency_id'] ?? ''));
	$buyQuantity = trim((string) ($_POST['product_market_buy_quantity'] ?? ''));
	$buySourceUrl = trim((string) ($_POST['product_market_buy_source_url'] ?? $productMarketUrl));

	if (preg_match('/^\d+$/', $buyQuantity) !== 1 || (int) $buyQuantity < 1) {
		$buyQuantity = '1';
	}

	$productMarketBuyResult = [
		'attempted' => true,
		'saved' => false,
		'reason' => 'product-market-buy-data-missing',
		'httpStatus' => 0,
		'url' => '',
		'offerId' => $buyOfferId,
		'quantity' => $buyQuantity,
		'currencyId' => $buyCurrencyId,
		'responseSnippet' => '',
		'requestPayload' => [],
		'error' => '',
	];

	if (preg_match('/^\d+$/', $buyOfferId) === 1 && preg_match('/^\d+$/', $buyCurrencyId) === 1) {
		$productMarketBuyResult = submitProductMarketBuy($ch, $buySourceUrl, $headers, [
			'offerId' => $buyOfferId,
			'quantity' => $buyQuantity,
			'currencyId' => $buyCurrencyId,
		]);
	}
}

if ($fatalError === '' && ($productMarketOffersRequested || $productMarketBuyRequested)) {
	$requestedType = strtoupper(trim((string) ($_POST['product_market_type'] ?? 'FOOD')));
	$allowedTypes = ['FOOD', 'GIFT', 'WEAPON', 'TICKET'];
	if (!in_array($requestedType, $allowedTypes, true)) {
		$requestedType = 'FOOD';
	}

	$requestedQuality = trim((string) ($_POST['product_market_quality'] ?? ''));
	$defaultQualityByType = [
		'FOOD' => '2',
		'GIFT' => '2',
		'WEAPON' => '1',
		'TICKET' => '1',
	];
	if (preg_match('/^\d+$/', $requestedQuality) !== 1 || (int) $requestedQuality < 1) {
		$requestedQuality = (string) ($defaultQualityByType[$requestedType] ?? '1');
	}

	$requestedCountryId = trim((string) ($_POST['product_market_country_id'] ?? '-1'));
	if (preg_match('/^-?\d+$/', $requestedCountryId) !== 1) {
		$requestedCountryId = '-1';
	}

	$requestedPage = trim((string) ($_POST['product_market_page'] ?? ''));
	if ($requestedPage !== '' && (preg_match('/^\d+$/', $requestedPage) !== 1 || (int) $requestedPage < 1)) {
		$requestedPage = '';
	}

	$productOffersUrl = buildProductMarketOffersUrl(
		$productMarketOffersBaseUrl,
		$requestedQuality,
		$requestedType,
		$requestedCountryId,
		$requestedPage
	);

	$productOffersStep = curlRequest($ch, $productOffersUrl, [
		CURLOPT_POST => false,
		CURLOPT_HTTPGET => true,
		CURLOPT_HTTPHEADER => $headers,
	]);

	$productOffersBody = (string) ($productOffersStep['body'] ?? '');
	$productOffersOk = $productOffersStep['errno'] === 0
		&& (int) ($productOffersStep['statusCode'] ?? 0) >= 200
		&& (int) ($productOffersStep['statusCode'] ?? 0) < 400
		&& trim($productOffersBody) !== '';
	$productOffersItems = $productOffersOk
		? extractProductMarketOffersFromHtml($productOffersBody, (string) ($productOffersStep['effectiveUrl'] ?: $productOffersUrl))
		: [];

	$productMarketOffersResult = [
		'attempted' => true,
		'saved' => $productOffersOk,
		'reason' => $productOffersStep['errno'] !== 0
			? 'product-market-offers-request-error'
			: ($productOffersOk ? (count($productOffersItems) > 0 ? 'product-market-offers-loaded' : 'product-market-offers-empty') : 'product-market-offers-http-error'),
		'httpStatus' => (int) ($productOffersStep['statusCode'] ?? 0),
		'url' => (string) ($productOffersStep['effectiveUrl'] ?: $productOffersUrl),
		'bodyLength' => strlen($productOffersBody),
		'type' => $requestedType,
		'quality' => $requestedQuality,
		'countryId' => $requestedCountryId,
		'page' => $requestedPage,
		'offers' => $productOffersItems,
		'itemsCount' => count($productOffersItems),
		'error' => (string) ($productOffersStep['error'] ?? ''),
	];
}

if ($fatalError === '' && $regionTravelLoadRequested) {
	$rawRegionUrl = trim((string) ($_POST['region_url'] ?? ($_POST['travel_region_select'] ?? '')));
	$normalizedRegionUrl = normalizeRegionPageUrl($rawRegionUrl, (string) ($step3['effectiveUrl'] ?? serverUrl('index.html')));

	$regionTravelLookupResult = [
		'attempted' => true,
		'saved' => false,
		'reason' => $normalizedRegionUrl === '' ? 'region-url-invalid' : 'region-request-error',
		'httpStatus' => 0,
		'regionUrl' => $normalizedRegionUrl,
		'regionId' => '',
		'regionName' => '',
		'currentOwner' => '',
		'rightfulOwner' => '',
		'resource' => '',
		'travelForm' => emptyTravelFormData(),
		'error' => '',
	];

	if ($normalizedRegionUrl !== '') {
		$regionStep = curlRequest($ch, $normalizedRegionUrl, [
			CURLOPT_POST => false,
			CURLOPT_HTTPGET => true,
			CURLOPT_HTTPHEADER => $headers,
		]);

		$regionEffectiveUrl = (string) ($regionStep['effectiveUrl'] ?: $normalizedRegionUrl);
		$regionId = '';
		if (preg_match('/[?&]id=(\d+)/i', $regionEffectiveUrl, $matchRegionId) === 1) {
			$regionId = (string) ($matchRegionId[1] ?? '');
		}
		if ($regionId === '' && preg_match('/[?&]id=(\d+)/i', $normalizedRegionUrl, $matchRegionId) === 1) {
			$regionId = (string) ($matchRegionId[1] ?? '');
		}
		$regionSummary = extractRegionSummaryFromRegionHtml((string) ($regionStep['body'] ?? ''), $regionId);
		$travelForm = extractTravelFormDataFromRegionHtml((string) ($regionStep['body'] ?? ''), $regionEffectiveUrl);

		$regionOk = $regionStep['errno'] === 0
			&& (int) $regionStep['statusCode'] >= 200
			&& (int) $regionStep['statusCode'] < 400;
		$travelDataFound = (string) ($travelForm['actionUrl'] ?? '') !== ''
			&& preg_match('/^\d+$/', (string) ($travelForm['countryId'] ?? '')) === 1
			&& preg_match('/^\d+$/', (string) ($travelForm['regionId'] ?? '')) === 1;

		$regionTravelLookupResult = [
			'attempted' => true,
			'saved' => $regionOk && $travelDataFound,
			'reason' => !$regionOk
				? ($regionStep['errno'] !== 0 ? 'region-request-error' : 'region-http-error')
				: ($travelDataFound ? 'region-travel-form-loaded' : 'region-travel-form-not-found'),
			'httpStatus' => (int) ($regionStep['statusCode'] ?? 0),
			'regionUrl' => $regionEffectiveUrl,
			'regionId' => $regionId,
			'regionName' => (string) ($regionSummary['regionName'] ?? ''),
			'currentOwner' => (string) ($regionSummary['currentOwner'] ?? ''),
			'rightfulOwner' => (string) ($regionSummary['rightfulOwner'] ?? ''),
			'resource' => (string) ($regionSummary['resource'] ?? ''),
			'travelForm' => $travelForm,
			'error' => (string) ($regionStep['error'] ?? ''),
		];
	}
}

if ($fatalError === '' && $travelRequested) {
	$travelAttempted = true;
	$travelActionUrl = trim((string) ($_POST['travel_action_url'] ?? ''));
	$travelCountryId = trim((string) ($_POST['travel_country_id'] ?? ''));
	$travelRegionId = trim((string) ($_POST['travel_region_id'] ?? ''));
	$travelRedirectUrl = trim((string) ($_POST['travel_redirect_url'] ?? ''));
	$travelTicketQuality = trim((string) ($_POST['travel_ticket_quality'] ?? '1'));
	$travelDestination = trim((string) ($_POST['travel_destination'] ?? ''));

	if ($travelRedirectUrl === '' && $travelRegionId !== '' && preg_match('/^\d+$/', $travelRegionId)) {
		$travelRedirectUrl = 'region.html?id=' . $travelRegionId;
	}
	if (!preg_match('/^\d+$/', $travelTicketQuality)) {
		$travelTicketQuality = '1';
	}

	$travelResult = [
		'attempted' => true,
		'saved' => false,
		'reason' => 'travel-form-data-missing',
		'actionUrl' => $travelActionUrl,
		'httpStatus' => 0,
		'destination' => $travelDestination,
		'ticketQuality' => $travelTicketQuality,
	];

	if ($travelActionUrl !== '' && preg_match('/^\d+$/', $travelCountryId) && preg_match('/^\d+$/', $travelRegionId)) {
		$travelStep = submitTravelTask($ch, (string) $step3['effectiveUrl'], $headers, $travelActionUrl, [
			'countryId' => $travelCountryId,
			'regionId' => $travelRegionId,
			'redirectUrl' => $travelRedirectUrl,
			'ticketQuality' => $travelTicketQuality,
		]);
		$travelAccepted = $travelStep['errno'] === 0
			&& (int) $travelStep['statusCode'] >= 200
			&& (int) $travelStep['statusCode'] < 400;
		$travelResult = [
			'attempted' => true,
			'saved' => $travelAccepted,
			'reason' => $travelAccepted ? 'travel-submitted' : ($travelStep['errno'] !== 0 ? 'travel-request-error' : 'travel-rejected'),
			'actionUrl' => (string) ($travelStep['url'] ?: $travelActionUrl),
			'httpStatus' => (int) $travelStep['statusCode'],
			'error' => (string) $travelStep['error'],
			'destination' => $travelDestination,
			'ticketQuality' => $travelTicketQuality,
		];

		$step3 = curlRequest($ch, $baseUrl, [
			CURLOPT_POST => false,
			CURLOPT_HTTPGET => true,
			CURLOPT_HTTPHEADER => $headers,
		]);

		if ($step3['errno'] !== 0) {
			$fatalError = 'GET post-travel fallo: ' . $step3['error'];
		}
	}
}

if ($fatalError === '' && $battleActionRequested) {
	$battleActionAttempted = true;
	$battleActionUrl = trim((string) ($_POST['battle_action_url'] ?? ''));
	$battlePageUrl = trim((string) ($_POST['battle_page_url'] ?? ''));
	$battleTitle = trim((string) ($_POST['battle_title'] ?? ''));
	$battleActionResult = [
		'attempted' => true,
		'saved' => false,
		'reason' => 'battle-action-url-missing',
		'type' => $battleActionType,
		'battleTitle' => $battleTitle,
		'actionUrl' => $battleActionUrl,
		'requestPayload' => [],
		'requestPayloadEncoded' => '',
		'httpStatus' => 0,
		'damage' => '',
	];

	if ($battleActionUrl !== '') {
		$fightPayload = [];
		if ($battleActionType === 'battle-fight-request') {
			$weaponQuality = (string) ($_POST['battle_weapon_quality'] ?? '0');
			if (!in_array($weaponQuality, ['0', '1', '5'], true)) {
				$weaponQuality = '0';
			}

			$roundId = (string) ($_POST['battle_round_id'] ?? '0');
			if (!preg_match('/^\d+$/', $roundId) || (int) $roundId < 1) {
				$roundId = '1';
			}

			$side = (string) ($_POST['battle_side'] ?? 'defender');
			if (!in_array($side, ['attacker', 'defender'], true)) {
				$side = 'defender';
			}

			$value = (string) ($_POST['battle_value'] ?? 'Normal');

			$fightPayload = [
				'weaponQuality' => $weaponQuality,
				'battleRoundId' => $roundId,
				'side' => $side,
				'value' => $value
			];

			foreach (['ip', 'serverName', 'citizenId', 'myCitizenship', 'citizenRegion', 'securityHash', 'mousePattern', 'gameDay'] as $key) {
				$extraValue = trim((string) ($_POST['fight_' . $key] ?? ''));
				if ($extraValue !== '') {
					$fightPayload[$key] = $extraValue;
				}
			}

			$battleStep = submitBattleAction($ch, $battleActionUrl, $battlePageUrl, $headers, $fightPayload);
		} else {
			$battleStep = submitBattleAction($ch, $battleActionUrl, $battlePageUrl, $headers);
		}

		$battleAccepted = $battleStep['errno'] === 0
			&& (int) $battleStep['statusCode'] >= 200
			&& (int) $battleStep['statusCode'] < 400;
		$battleActionResult = [
			'attempted' => true,
			'saved' => $battleAccepted,
			'reason' => $battleAccepted ? 'battle-action-submitted' : ($battleStep['errno'] !== 0 ? 'battle-action-request-error' : 'battle-action-rejected'),
			'type' => $battleActionType,
			'battleTitle' => $battleTitle . ($battleActionType === 'battle-fight-request' ? ' | ' . (string) ($_POST['battle_side'] ?? '') . ' | q' . (string) ($_POST['battle_weapon_quality'] ?? '') . ' | x' . (string) ($_POST['battle_value'] ?? '') : ''),
			'actionUrl' => (string) ($battleStep['url'] ?: $battleActionUrl),
			'requestPayload' => $battleActionType === 'battle-fight-request' ? $fightPayload : [],
			'requestPayloadEncoded' => $battleActionType === 'battle-fight-request' ? http_build_query($fightPayload) : '',
			'httpStatus' => (int) $battleStep['statusCode'],
			'error' => (string) $battleStep['error'],
			'energy' => extractEnergyFromAnyResponse((string) ($battleStep['body'] ?? '')),
			'damage' => extractDamageFromAnyResponse((string) ($battleStep['body'] ?? '')),
		];
	}
}

if ($fatalError === '' && !($isAsyncActionRequest && ($trainRequested || $workRequested || $eatRequested || $drinkRequested || $leaveJobRequested || $travelRequested || $battleActionRequested || $companyOfferApplyRequested))) {
	$workplaceStep = curlRequest($ch, $workplaceUrl, [
		CURLOPT_POST => false,
		CURLOPT_HTTPGET => true,
		CURLOPT_HTTPHEADER => $headers,
	]);
	$workplaceBody = (string) ($workplaceStep['body'] ?? '');
	$workplaceOk = $workplaceStep['errno'] === 0
		&& (int) $workplaceStep['statusCode'] >= 200
		&& (int) $workplaceStep['statusCode'] < 400;
	$workInfo = extractWorkplaceInfo($workplaceBody, (string) ($workplaceStep['effectiveUrl'] ?: $workplaceUrl));
	$workplaceResult = [
		'attempted' => true,
		'saved' => $workplaceOk,
		'reason' => $workplaceOk ? 'workplace-loaded' : ($workplaceStep['errno'] !== 0 ? 'workplace-request-error' : 'workplace-http-error'),
		'httpStatus' => (int) $workplaceStep['statusCode'],
		'url' => (string) ($workplaceStep['effectiveUrl'] ?: $workplaceUrl),
		'companyName' => (string) ($workInfo['companyName'] ?? ''),
		'companyUrl' => (string) ($workInfo['companyUrl'] ?? ''),
		'companyOwner' => (string) ($workInfo['companyOwner'] ?? ''),
		'companyOwnerType' => (string) ($workInfo['companyOwnerType'] ?? ''),
		'companyOwnerUrl' => (string) ($workInfo['companyOwnerUrl'] ?? ''),
		'canWork' => (bool) ($workInfo['canWork'] ?? false),
		'canLeave' => (bool) ($workInfo['canLeave'] ?? false),
		'leaveActionUrl' => (string) ($workInfo['leaveActionUrl'] ?? ''),
		'leaveFields' => is_array($workInfo['leaveFields'] ?? null) ? $workInfo['leaveFields'] : [],
		'error' => (string) ($workplaceStep['error'] ?? ''),
	];

	$battlesItems = [];
	$maxBattlePagesToScan = 3;
	$pagesScanned = 0;
	$practiceFound = false;
	$battlesBodyLength = 0;
	$battlesOk = false;
	$lastBattlesStep = [
		'statusCode' => 0,
		'effectiveUrl' => $battlesUrl,
		'errno' => 0,
		'error' => '',
	];

	for ($battlePage = 1; $battlePage <= $maxBattlePagesToScan; $battlePage++) {
		$battlePageUrl = serverUrl('battles.html?countryId=-1&page=') . $battlePage;
		$battlesStep = curlRequest($ch, $battlePageUrl, [
			CURLOPT_POST => false,
			CURLOPT_HTTPGET => true,
			CURLOPT_HTTPHEADER => $headers,
		]);

		$lastBattlesStep = $battlesStep;
		$pagesScanned++;

		$pageBody = (string) ($battlesStep['body'] ?? '');
		$battlesBodyLength += strlen($pageBody);

		$pageOk = $battlesStep['errno'] === 0
			&& (int) $battlesStep['statusCode'] >= 200
			&& (int) $battlesStep['statusCode'] < 400;
		if (!$pageOk) {
			$battlesOk = false;
			break;
		}

		$battlesOk = true;
		$pageItems = extractAvailableBattles($pageBody, (string) ($battlesStep['effectiveUrl'] ?: $battlePageUrl));
		foreach ($pageItems as $pageItem) {
			if (!is_array($pageItem)) {
				continue;
			}

			$itemUrl = trim((string) ($pageItem['url'] ?? ''));
			if ($itemUrl === '') {
				continue;
			}

			$alreadyListed = false;
			foreach ($battlesItems as $existingItem) {
				if (is_array($existingItem) && trim((string) ($existingItem['url'] ?? '')) === $itemUrl) {
					$alreadyListed = true;
					break;
				}
			}
			if ($alreadyListed) {
				continue;
			}

			$battlesItems[] = $pageItem;
			if (isPracticeBattleItem($pageItem)) {
				$practiceFound = true;
				break;
			}
		}

		if ($practiceFound) {
			break;
		}
	}
	if ($battleInspectRequested && $battlesOk) {
		$requestedBattleUrl = trim((string) ($_POST['battle_page_url'] ?? ''));
		$requestedBattleTitle = trim((string) ($_POST['battle_title'] ?? ''));
		$battleInspectResult = [
			'attempted' => true,
			'saved' => false,
			'reason' => $requestedBattleUrl === '' ? 'battle-inspect-url-missing' : 'battle-inspect-not-found',
			'httpStatus' => 0,
			'battleUrl' => $requestedBattleUrl,
			'battleTitle' => $requestedBattleTitle,
		];

		if ($requestedBattleUrl !== '') {
			foreach ($battlesItems as $idx => $battleItem) {
				$itemUrl = trim((string) ($battleItem['url'] ?? ''));
				if ($itemUrl === '' || $itemUrl !== $requestedBattleUrl) {
					continue;
				}

				$detail = inspectBattleCombatState($ch, $headers, $itemUrl);
				$battleDetailsCache[$itemUrl] = $detail;
				if (count($battleDetailsCache) > 50) {
					$battleDetailsCache = array_slice($battleDetailsCache, -50, null, true);
				}
				$battlesItems[$idx] = attachBattleCombatDetailToItem(is_array($battleItem) ? $battleItem : [], $detail);
				$battlesItems[$idx]['detailsLoaded'] = true;
				$battleInspectResult = [
					'attempted' => true,
					'saved' => true,
					'reason' => (string) ($detail['reason'] ?? 'battle-detail-loaded'),
					'httpStatus' => (int) ($detail['httpStatus'] ?? 0),
					'battleUrl' => $itemUrl,
					'battleTitle' => (string) (($battleItem['title'] ?? '') !== '' ? (string) $battleItem['title'] : $requestedBattleTitle),
				];
				break;
			}
		}
	}

	foreach ($battlesItems as $idx => $battleItem) {
		if (!is_array($battleItem)) {
			continue;
		}

		$itemUrl = trim((string) ($battleItem['url'] ?? ''));
		if ($itemUrl !== '' && isset($battleDetailsCache[$itemUrl]) && is_array($battleDetailsCache[$itemUrl])) {
			$battlesItems[$idx] = attachBattleCombatDetailToItem($battleItem, (array) $battleDetailsCache[$itemUrl]);
			$battlesItems[$idx]['detailsLoaded'] = true;
		}
	}

	foreach ($battlesItems as $idx => $battleItem) {
		if (!is_array($battleItem)) {
			continue;
		}
		if (!array_key_exists('detailsLoaded', $battleItem)) {
			$battlesItems[$idx]['detailsLoaded'] = false;
		}
	}
	$battlesResult = [
		'attempted' => true,
		'saved' => $battlesOk,
		'reason' => $battlesOk
			? ($practiceFound ? 'battles-loaded-practice-found' : 'battles-loaded-practice-not-found')
			: (($lastBattlesStep['errno'] ?? 0) !== 0 ? 'battles-request-error' : 'battles-http-error'),
		'httpStatus' => (int) ($lastBattlesStep['statusCode'] ?? 0),
		'url' => (string) (($lastBattlesStep['effectiveUrl'] ?? '') !== '' ? (string) $lastBattlesStep['effectiveUrl'] : $battlesUrl),
		'items' => $battlesItems,
		'bodyLength' => $battlesBodyLength,
		'pagesScanned' => $pagesScanned,
		'practiceFound' => $practiceFound,
		'error' => (string) ($lastBattlesStep['error'] ?? ''),
	];

	$_SESSION['battle_details_cache'] = $battleDetailsCache;
}

if ($fatalError === '' && !$isAsyncActionRequest) {
	$travelSourceBaseUrl = (string) ($step3['effectiveUrl'] ?: serverUrl('index.html'));
	$travelCountriesUrl = resolveUrl($travelSourceBaseUrl, 'travel.html');
	$travelCountriesStep = curlRequest($ch, $travelCountriesUrl, [
		CURLOPT_POST => false,
		CURLOPT_HTTPGET => true,
		CURLOPT_HTTPHEADER => $headers,
	]);
	$countries = extractTravelCountriesFromTravelHtml((string) ($travelCountriesStep['body'] ?? ''));
	$countriesOk = $travelCountriesStep['errno'] === 0
		&& (int) $travelCountriesStep['statusCode'] >= 200
		&& (int) $travelCountriesStep['statusCode'] < 400;
	$travelCountryListResult = [
		'attempted' => true,
		'saved' => $countriesOk,
		'reason' => !$countriesOk
			? ($travelCountriesStep['errno'] !== 0 ? 'travel-countries-request-error' : 'travel-countries-http-error')
			: (!empty($countries) ? 'travel-countries-loaded' : 'travel-countries-empty'),
		'httpStatus' => (int) ($travelCountriesStep['statusCode'] ?? 0),
		'sourceUrl' => (string) ($travelCountriesStep['effectiveUrl'] ?: $travelCountriesUrl),
		'countries' => $countries,
		'error' => (string) ($travelCountriesStep['error'] ?? ''),
	];

	$selectedCountryId = trim((string) ($_POST['travel_country_select'] ?? ''));
	if ($selectedCountryId === '' && preg_match('/^\d+$/', (string) ($regionTravelLookupResult['travelForm']['countryId'] ?? '')) === 1) {
		$selectedCountryId = trim((string) $regionTravelLookupResult['travelForm']['countryId']);
	}

	if (preg_match('/^\d+$/', $selectedCountryId) === 1 && ($travelCountryLoadRequested || $regionTravelLoadRequested || $travelRequested || $selectedCountryId !== '')) {
		$regionsStep = fetchCountryRegionsStep($ch, $headers, $travelSourceBaseUrl, $selectedCountryId);
		$regions = extractCountryRegionsFromHtml((string) ($regionsStep['body'] ?? ''));
		$regionsOk = $regionsStep['errno'] === 0
			&& (int) $regionsStep['statusCode'] >= 200
			&& (int) $regionsStep['statusCode'] < 400;
		$travelCountryRegionsResult = [
			'attempted' => true,
			'saved' => $regionsOk,
			'reason' => !$regionsOk
				? ($regionsStep['errno'] !== 0 ? 'country-regions-request-error' : 'country-regions-http-error')
				: (!empty($regions) ? 'country-regions-loaded' : 'country-regions-empty'),
			'httpStatus' => (int) ($regionsStep['statusCode'] ?? 0),
			'sourceUrl' => (string) ($regionsStep['effectiveUrl'] ?: $regionsStep['url']),
			'countryId' => $selectedCountryId,
			'regions' => $regions,
			'error' => (string) ($regionsStep['error'] ?? ''),
		];
	}

	if ($regionsCatalogAnalyzeCountryRequested) {
		$analyzeCountryId = trim((string) ($_POST['travel_country_select'] ?? ''));
		$regionsCatalogManualResult = [
			'attempted' => true,
			'saved' => false,
			'reason' => preg_match('/^\d+$/', $analyzeCountryId) === 1 ? 'catalog-country-analyze-failed' : 'catalog-country-id-invalid',
			'countryId' => $analyzeCountryId,
			'countryName' => '',
			'regionsProcessed' => 0,
			'catalogPath' => $regionsCatalogManualPath,
			'error' => '',
		];

		$analyzeCountryName = '';
		foreach ((array) ($travelCountryListResult['countries'] ?? []) as $countryItem) {
			$countryItemId = trim((string) ($countryItem['id'] ?? ''));
			if ($countryItemId === $analyzeCountryId) {
				$analyzeCountryName = trim((string) ($countryItem['name'] ?? ''));
				break;
			}
		}
		$regionsCatalogManualResult['countryName'] = $analyzeCountryName;

		if (preg_match('/^\d+$/', $analyzeCountryId) === 1) {
			$countryRegions = is_array($travelCountryRegionsResult['regions'] ?? null)
				? (array) $travelCountryRegionsResult['regions']
				: [];

			if (empty($countryRegions) || (string) ($travelCountryRegionsResult['countryId'] ?? '') !== $analyzeCountryId) {
				$regionsStep = fetchCountryRegionsStep($ch, $headers, $travelSourceBaseUrl, $analyzeCountryId);
				$countryRegions = extractCountryRegionsFromHtml((string) ($regionsStep['body'] ?? ''));
			}

			$catalogRegions = [];
			foreach ($countryRegions as $regionItem) {
				$regionId = trim((string) ($regionItem['id'] ?? ''));
				$regionName = trim((string) ($regionItem['name'] ?? ''));
				$occupationText = trim((string) ($regionItem['occupation'] ?? ''));
				if (!preg_match('/^\d+$/', $regionId) || $regionName === '') {
					continue;
				}

				$regionUrl = resolveUrl($travelSourceBaseUrl, 'region.html?id=' . $regionId);
				$regionStep = curlRequest($ch, $regionUrl, [
					CURLOPT_POST => false,
					CURLOPT_HTTPGET => true,
					CURLOPT_HTTPHEADER => $headers,
				]);
				$regionSummary = extractRegionSummaryFromRegionHtml((string) ($regionStep['body'] ?? ''), $regionId);
				$resource = trim((string) ($regionSummary['resource'] ?? ''));
				$normalizedResource = normalizeSidebarLabel($resource);
				$hasResource = $resource !== ''
					&& !str_contains($normalizedResource, 'no resources')
					&& !str_contains($normalizedResource, 'sin recursos')
					&& !str_contains($normalizedResource, 'capital');

				$catalogRegions[] = [
					'id' => $regionId,
					'name' => (string) (($regionSummary['regionName'] ?? '') !== '' ? (string) $regionSummary['regionName'] : $regionName),
					'occupiedBy' => extractOccupiedByFromOccupationText($occupationText),
					'occupationText' => $occupationText,
					'hasResource' => $hasResource,
					'resource' => $resource,
					'currentOwner' => (string) ($regionSummary['currentOwner'] ?? ''),
					'rightfulOwner' => (string) ($regionSummary['rightfulOwner'] ?? ''),
					'detailFetched' => $regionStep['errno'] === 0 && (int) ($regionStep['statusCode'] ?? 0) >= 200 && (int) ($regionStep['statusCode'] ?? 0) < 400,
					'detailHttpStatus' => (int) ($regionStep['statusCode'] ?? 0),
					'url' => $regionUrl,
				];
			}

			$catalogData = loadRegionsManualCatalog($regionsCatalogManualPath, extractHostFromUrl($travelSourceBaseUrl));
			$catalogData = upsertCountryIntoRegionsManualCatalog($catalogData, $analyzeCountryId, $analyzeCountryName, $catalogRegions);
			$saved = saveRegionsManualCatalog($regionsCatalogManualPath, $catalogData);

			$regionsCatalogManualResult['saved'] = $saved;
			$regionsCatalogManualResult['reason'] = $saved ? 'catalog-country-saved' : 'catalog-save-failed';
			$regionsCatalogManualResult['regionsProcessed'] = count($catalogRegions);
			if (!$saved) {
				$regionsCatalogManualResult['error'] = 'No se pudo guardar regions_catalog_manual.json';
			}
		}
	}

	$catalogStatusData = loadRegionsManualCatalog($regionsCatalogManualPath, extractHostFromUrl($travelSourceBaseUrl));
	$regionsCatalogManualStatus = [
		'exists' => is_file($regionsCatalogManualPath),
		'countryCount' => (int) ($catalogStatusData['countryCount'] ?? 0),
		'regionCount' => (int) ($catalogStatusData['regionCount'] ?? 0),
		'path' => $regionsCatalogManualPath,
	];
}

curl_close($ch);

if ($isAsyncActionRequest) {
	$response = [
		'success' => false,
		'notify' => true,
		'action' => (string) ($_POST['action'] ?? ''),
		'message' => 'No se pudo completar la accion.',
		'reason' => 'unknown',
		'httpStatus' => 0,
		'energy' => '',
	];

	if ($fatalError !== '') {
		$response['message'] = $fatalError;
		$response['reason'] = 'fatal-error';
	} elseif ($trainRequested) {
		$ok = !empty($trainResult['saved']);
		$energyNow = extractEnergyFromAnyResponse((string) ($step3['body'] ?? ''));
		$response['success'] = $ok;
		$response['notify'] = true;
		$response['message'] = $ok ? 'Entrenar enviado correctamente.' : 'Entrenar fallo: ' . (string) ($trainResult['reason'] ?? 'unknown');
		$response['reason'] = (string) ($trainResult['reason'] ?? 'unknown');
		$response['httpStatus'] = (int) ($trainResult['httpStatus'] ?? 0);
		$response['energy'] = $energyNow;
	} elseif ($workRequested) {
		$ok = !empty($workResult['saved']);
		$energyNow = extractEnergyFromAnyResponse((string) ($step3['body'] ?? ''));
		$response['success'] = $ok;
		$response['notify'] = true;
		$response['message'] = $ok ? 'Trabajar enviado correctamente.' : 'Trabajar fallo: ' . (string) ($workResult['reason'] ?? 'unknown');
		$response['reason'] = (string) ($workResult['reason'] ?? 'unknown');
		$response['httpStatus'] = (int) ($workResult['httpStatus'] ?? 0);
		$response['energy'] = $energyNow;
	} elseif ($eatRequested) {
		$ok = !empty($eatResult['saved']);
		$response['success'] = $ok;
		$response['notify'] = true;
		$response['message'] = $ok ? 'Comida usada correctamente.' : 'Comer fallo: ' . (string) ($eatResult['reason'] ?? 'unknown');
		$response['reason'] = (string) ($eatResult['reason'] ?? 'unknown');
		$response['httpStatus'] = (int) ($eatResult['httpStatus'] ?? 0);
		$response['energy'] = (string) ($eatResult['energy'] ?? '');
	} elseif ($drinkRequested) {
		$ok = !empty($drinkResult['saved']);
		$response['success'] = $ok;
		$response['notify'] = true;
		$response['message'] = $ok ? 'Bebida usada correctamente.' : 'Beber fallo: ' . (string) ($drinkResult['reason'] ?? 'unknown');
		$response['reason'] = (string) ($drinkResult['reason'] ?? 'unknown');
		$response['httpStatus'] = (int) ($drinkResult['httpStatus'] ?? 0);
		$response['energy'] = (string) ($drinkResult['energy'] ?? '');
	} elseif ($leaveJobRequested) {
		$ok = !empty($leaveJobResult['saved']);
		$response['success'] = $ok;
		$response['notify'] = true;
		$response['message'] = $ok ? 'Renuncia enviada correctamente.' : 'Renunciar fallo: ' . (string) ($leaveJobResult['reason'] ?? 'unknown');
		$response['reason'] = (string) ($leaveJobResult['reason'] ?? 'unknown');
		$response['httpStatus'] = (int) ($leaveJobResult['httpStatus'] ?? 0);
		$response['energy'] = extractEnergyFromAnyResponse((string) ($step3['body'] ?? ''));
		$response['reloadBattles'] = $ok;
	} elseif ($travelRequested) {
		$ok = !empty($travelResult['saved']);
		$response['success'] = $ok;
		$response['notify'] = true;
		$response['message'] = $ok
			? 'Viaje enviado correctamente' . ((string) ($travelResult['destination'] ?? '') !== '' ? ': ' . (string) $travelResult['destination'] : '') . '.'
			: 'Viajar fallo: ' . (string) ($travelResult['reason'] ?? 'unknown');
		$response['reason'] = (string) ($travelResult['reason'] ?? 'unknown');
		$response['httpStatus'] = (int) ($travelResult['httpStatus'] ?? 0);
		$response['reloadBattles'] = $ok;
		$response['energy'] = extractEnergyFromAnyResponse((string) ($step3['body'] ?? ''));
	} elseif ($battleActionRequested) {
		$ok = !empty($battleActionResult['saved']);
		$type = (string) ($battleActionResult['type'] ?? 'battle-action');
		$damage = trim((string) ($battleActionResult['damage'] ?? ''));
		$requestUrl = trim((string) ($battleActionResult['actionUrl'] ?? ''));
		$requestPayload = is_array($battleActionResult['requestPayload'] ?? null) ? (array) $battleActionResult['requestPayload'] : [];
		$requestPayloadEncoded = trim((string) ($battleActionResult['requestPayloadEncoded'] ?? ''));
		$isHitOrBerserk = $type === 'battle-fight-request';
		$response['success'] = $ok;
		$response['notify'] = true;
		if ($ok) {
			$response['message'] = 'Accion de batalla enviada.'
				. ($damage !== '' ? ' Dano: ' . $damage : '')
				. ($isHitOrBerserk && $requestUrl !== '' ? ' URL cURL: ' . $requestUrl : '')
				. ($isHitOrBerserk && $requestPayloadEncoded !== '' ? ' Payload: ' . $requestPayloadEncoded : '');
		} else {
			$response['message'] = 'Accion de batalla fallo: ' . (string) ($battleActionResult['reason'] ?? 'unknown')
				. ($isHitOrBerserk && $requestUrl !== '' ? ' URL cURL: ' . $requestUrl : '')
				. ($isHitOrBerserk && $requestPayloadEncoded !== '' ? ' Payload: ' . $requestPayloadEncoded : '');
		}
		$response['reason'] = (string) ($battleActionResult['reason'] ?? 'unknown');
		$response['httpStatus'] = (int) ($battleActionResult['httpStatus'] ?? 0);
		$response['battleType'] = $type;
		$response['requestUrl'] = $isHitOrBerserk ? $requestUrl : '';
		$response['requestPayload'] = $isHitOrBerserk ? $requestPayload : [];
		$response['requestPayloadEncoded'] = $isHitOrBerserk ? $requestPayloadEncoded : '';
		$response['energy'] = (string) ($battleActionResult['energy'] ?? '');
		$response['damage'] = $damage;
	} elseif ($battleInspectRequested) {
		$ok = !empty($battleInspectResult['saved']);
		$response['success'] = $ok;
		$response['notify'] = true;
		$response['message'] = $ok
			? 'Batalla cargada: ' . (string) (($battleInspectResult['battleTitle'] ?? '') !== '' ? (string) $battleInspectResult['battleTitle'] : 'OK')
			: 'Cargar batalla fallo: ' . (string) ($battleInspectResult['reason'] ?? 'unknown');
		$response['reason'] = (string) ($battleInspectResult['reason'] ?? 'unknown');
		$response['httpStatus'] = (int) ($battleInspectResult['httpStatus'] ?? 0);
		$response['refreshBattlesSection'] = true;
	} elseif ($companyOfferApplyRequested) {
		$ok = !empty($companyOfferApplyResult['saved']);
		$response['success'] = $ok;
		$response['notify'] = true;
		$response['message'] = $ok
			? 'Postulacion enviada correctamente para la oferta #' . (string) ($companyOfferApplyResult['offerId'] ?? '-') . '.'
			: 'Postulacion fallo: ' . (string) ($companyOfferApplyResult['reason'] ?? 'unknown');
		$response['reason'] = (string) ($companyOfferApplyResult['reason'] ?? 'unknown');
		$response['httpStatus'] = (int) ($companyOfferApplyResult['httpStatus'] ?? 0);
		$response['reloadPage'] = $ok;
	} else {
		$response['message'] = 'Accion no soportada en modo asincrono.';
		$response['reason'] = 'unsupported-action';
	}

	header('Content-Type: application/json; charset=UTF-8');
	echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;
}

header('Content-Type: text/html; charset=UTF-8');

if ($fatalError !== '') {
	echo '<h1>Error cURL</h1>';
	echo '<p><strong>Mensaje:</strong> ' . htmlspecialchars($fatalError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
	exit;
}

$safeBody = is_string($step3['body']) ? $step3['body'] : '';
$bodyLength = strlen($safeBody);
$authenticated = looksAuthenticated($safeBody);
$showTrainButton = $authenticated && hasTaskTrainButton($safeBody);
$showWorkButton = $authenticated && hasTaskWorkButton($safeBody);
$tutorialMissionState = extractTutorialMissionStateFromHtml(
	$safeBody,
	(string) (($step3['effectiveUrl'] ?? '') !== '' ? $step3['effectiveUrl'] : serverUrl('index.html'))
);
if (empty($freeStarterPackOpenResult['saved'])) {
	$freeStarterPackResult = detectFreeStarterPackFromHtml(
		$safeBody,
		(string) (($step3['effectiveUrl'] ?? '') !== '' ? $step3['effectiveUrl'] : serverUrl('index.html'))
	);
}
$freeStarterPackProxyClaimUrl = '';
if (trim((string) ($freeStarterPackResult['claimUrl'] ?? '')) !== '') {
	$freeStarterPackProxyClaimUrl = 'esim.php?u=' . rawurlencode((string) $freeStarterPackResult['claimUrl']) . '&fast=0';
}
$playerInfo = extractLoggedPlayerInfo($safeBody);
$headerCitizenId = trim((string) ($playerInfo['citizenId'] ?? '')) !== ''
	? trim((string) $playerInfo['citizenId'])
	: trim($userId);

$_SESSION['curl_last_login'] = [
	'when' => date('c'),
	'user' => $username,
	'authenticated' => $authenticated,
	'session_reused' => $sessionReused,
	'login_attempted' => $loginAttempted,
	'registration_attempted' => $registrationAttempted,
	'registration_auto_blocked' => $registrationAutoBlocked,
	'allow_auto_registration' => $allowAutoRegistration,
	'logged_user_validation' => [
		'checked' => (bool) ($loggedUserValidation['checked'] ?? false),
		'expected' => (string) ($loggedUserValidation['expected'] ?? ''),
		'actual' => (string) ($loggedUserValidation['actual'] ?? ''),
		'matched' => (bool) ($loggedUserValidation['matched'] ?? false),
		'reason' => (string) ($loggedUserValidation['reason'] ?? ''),
	],
	'registration_result' => $registrationResult,
	'logout_result' => [
		'attempted' => (bool) ($logoutResult['attempted'] ?? false),
		'saved' => (bool) ($logoutResult['saved'] ?? false),
		'reason' => (string) ($logoutResult['reason'] ?? ''),
		'httpStatus' => (int) ($logoutResult['httpStatus'] ?? 0),
		'url' => (string) ($logoutResult['url'] ?? ''),
		'localCookiesDeleted' => (int) ($logoutResult['localCookiesDeleted'] ?? 0),
	],
	'train_attempted' => $trainAttempted,
	'train_result' => $trainResult,
	'work_attempted' => $workAttempted,
	'work_result' => $workResult,
	'eat_attempted' => $eatAttempted,
	'eat_result' => $eatResult,
	'drink_attempted' => $drinkAttempted,
	'drink_result' => $drinkResult,
	'leave_job_attempted' => $leaveJobAttempted,
	'leave_job_result' => $leaveJobResult,
	'travel_attempted' => $travelAttempted,
	'travel_result' => $travelResult,
	'workplace_result' => [
		'attempted' => (bool) ($workplaceResult['attempted'] ?? false),
		'reason' => (string) ($workplaceResult['reason'] ?? ''),
		'httpStatus' => (int) ($workplaceResult['httpStatus'] ?? 0),
		'url' => (string) ($workplaceResult['url'] ?? ''),
		'companyName' => (string) ($workplaceResult['companyName'] ?? ''),
		'companyUrl' => (string) ($workplaceResult['companyUrl'] ?? ''),
		'companyOwner' => (string) ($workplaceResult['companyOwner'] ?? ''),
		'companyOwnerType' => (string) ($workplaceResult['companyOwnerType'] ?? ''),
		'companyOwnerUrl' => (string) ($workplaceResult['companyOwnerUrl'] ?? ''),
		'canWork' => (bool) ($workplaceResult['canWork'] ?? false),
		'canLeave' => (bool) ($workplaceResult['canLeave'] ?? false),
	],
	'company_offers_result' => [
		'attempted' => (bool) ($companyOffersResult['attempted'] ?? false),
		'reason' => (string) ($companyOffersResult['reason'] ?? ''),
		'httpStatus' => (int) ($companyOffersResult['httpStatus'] ?? 0),
		'sourceUrl' => (string) ($companyOffersResult['sourceUrl'] ?? ''),
		'companyName' => (string) ($companyOffersResult['companyName'] ?? ''),
		'offersCount' => is_array($companyOffersResult['offers'] ?? null) ? count((array) $companyOffersResult['offers']) : 0,
	],
	'company_offer_apply_result' => [
		'attempted' => (bool) ($companyOfferApplyResult['attempted'] ?? false),
		'reason' => (string) ($companyOfferApplyResult['reason'] ?? ''),
		'httpStatus' => (int) ($companyOfferApplyResult['httpStatus'] ?? 0),
		'actionUrl' => (string) ($companyOfferApplyResult['actionUrl'] ?? ''),
		'offerId' => (string) ($companyOfferApplyResult['offerId'] ?? ''),
	],
	'region_travel_lookup_result' => [
		'attempted' => (bool) ($regionTravelLookupResult['attempted'] ?? false),
		'reason' => (string) ($regionTravelLookupResult['reason'] ?? ''),
		'httpStatus' => (int) ($regionTravelLookupResult['httpStatus'] ?? 0),
		'regionUrl' => (string) ($regionTravelLookupResult['regionUrl'] ?? ''),
		'regionId' => (string) ($regionTravelLookupResult['regionId'] ?? ''),
		'regionName' => (string) ($regionTravelLookupResult['regionName'] ?? ''),
		'currentOwner' => (string) ($regionTravelLookupResult['currentOwner'] ?? ''),
		'rightfulOwner' => (string) ($regionTravelLookupResult['rightfulOwner'] ?? ''),
		'resource' => (string) ($regionTravelLookupResult['resource'] ?? ''),
		'hasTravelForm' => !empty(($regionTravelLookupResult['travelForm'] ?? [])['actionUrl']),
	],
	'notifications_result' => [
		'attempted' => (bool) ($notificationsResult['attempted'] ?? false),
		'reason' => (string) ($notificationsResult['reason'] ?? ''),
		'httpStatus' => (int) ($notificationsResult['httpStatus'] ?? 0),
		'url' => (string) ($notificationsResult['url'] ?? ''),
		'bodyLength' => (int) ($notificationsResult['bodyLength'] ?? 0),
		'itemsCount' => is_array($notificationsResult['items'] ?? null) ? count($notificationsResult['items']) : 0,
	],
	'dailies_result' => [
		'attempted' => (bool) ($dailiesResult['attempted'] ?? false),
		'saved' => (bool) ($dailiesResult['saved'] ?? false),
		'reason' => (string) ($dailiesResult['reason'] ?? ''),
		'httpStatus' => (int) ($dailiesResult['httpStatus'] ?? 0),
		'url' => (string) ($dailiesResult['url'] ?? ''),
		'bodyLength' => (int) ($dailiesResult['bodyLength'] ?? 0),
		'itemsCount' => is_array($dailiesResult['items'] ?? null) ? count($dailiesResult['items']) : 0,
		'claimableCount' => (int) ($dailiesResult['claimableCount'] ?? 0),
	],
	'dailies_claim_result' => [
		'attempted' => (bool) ($dailiesClaimResult['attempted'] ?? false),
		'saved' => (bool) ($dailiesClaimResult['saved'] ?? false),
		'reason' => (string) ($dailiesClaimResult['reason'] ?? ''),
		'httpStatus' => (int) ($dailiesClaimResult['httpStatus'] ?? 0),
		'url' => (string) ($dailiesClaimResult['url'] ?? ''),
		'claimUrl' => (string) ($dailiesClaimResult['claimUrl'] ?? ''),
		'dailyId' => (string) ($dailiesClaimResult['dailyId'] ?? ''),
	],
	'change_email_result' => [
		'attempted' => (bool) ($changeEmailResult['attempted'] ?? false),
		'saved' => (bool) ($changeEmailResult['saved'] ?? false),
		'reason' => (string) ($changeEmailResult['reason'] ?? ''),
		'httpStatus' => (int) ($changeEmailResult['httpStatus'] ?? 0),
		'url' => (string) ($changeEmailResult['url'] ?? ''),
		'email' => (string) ($changeEmailResult['email'] ?? ''),
	],
	'registered_email_result' => [
		'attempted' => (bool) ($registeredEmailResult['attempted'] ?? false),
		'saved' => (bool) ($registeredEmailResult['saved'] ?? false),
		'reason' => (string) ($registeredEmailResult['reason'] ?? ''),
		'httpStatus' => (int) ($registeredEmailResult['httpStatus'] ?? 0),
		'url' => (string) ($registeredEmailResult['url'] ?? ''),
		'email' => (string) ($registeredEmailResult['email'] ?? ''),
	],
	'resend_confirmation_mail_result' => [
		'attempted' => (bool) ($resendConfirmationMailResult['attempted'] ?? false),
		'saved' => (bool) ($resendConfirmationMailResult['saved'] ?? false),
		'reason' => (string) ($resendConfirmationMailResult['reason'] ?? ''),
		'httpStatus' => (int) ($resendConfirmationMailResult['httpStatus'] ?? 0),
		'url' => (string) ($resendConfirmationMailResult['url'] ?? ''),
	],
	'confirm_mail_code_result' => [
		'attempted' => (bool) ($confirmMailCodeResult['attempted'] ?? false),
		'saved' => (bool) ($confirmMailCodeResult['saved'] ?? false),
		'reason' => (string) ($confirmMailCodeResult['reason'] ?? ''),
		'httpStatus' => (int) ($confirmMailCodeResult['httpStatus'] ?? 0),
		'url' => (string) ($confirmMailCodeResult['url'] ?? ''),
		'citizenId' => (string) ($confirmMailCodeResult['citizenId'] ?? ''),
	],
	'party_status_check_result' => [
		'attempted' => (bool) ($partyStatusCheckResult['attempted'] ?? false),
		'saved' => (bool) ($partyStatusCheckResult['saved'] ?? false),
		'reason' => (string) ($partyStatusCheckResult['reason'] ?? ''),
		'httpStatus' => (int) ($partyStatusCheckResult['httpStatus'] ?? 0),
		'url' => (string) ($partyStatusCheckResult['url'] ?? ''),
		'needsEmailConfirmation' => (bool) ($partyStatusCheckResult['needsEmailConfirmation'] ?? false),
	],
	'party_inspect_result' => [
		'attempted' => (bool) ($partyInspectResult['attempted'] ?? false),
		'saved' => (bool) ($partyInspectResult['saved'] ?? false),
		'reason' => (string) ($partyInspectResult['reason'] ?? ''),
		'httpStatus' => (int) ($partyInspectResult['httpStatus'] ?? 0),
		'url' => (string) ($partyInspectResult['url'] ?? ''),
		'partyName' => (string) ($partyInspectResult['partyName'] ?? ''),
		'joinDetected' => (bool) ($partyInspectResult['joinDetected'] ?? false),
		'leaveDetected' => (bool) ($partyInspectResult['leaveDetected'] ?? false),
	],
	'party_join_result' => [
		'attempted' => (bool) ($partyJoinResult['attempted'] ?? false),
		'saved' => (bool) ($partyJoinResult['saved'] ?? false),
		'reason' => (string) ($partyJoinResult['reason'] ?? ''),
		'httpStatus' => (int) ($partyJoinResult['httpStatus'] ?? 0),
		'url' => (string) ($partyJoinResult['url'] ?? ''),
		'partyName' => (string) ($partyJoinResult['partyName'] ?? ''),
	],
	'party_leave_result' => [
		'attempted' => (bool) ($partyLeaveResult['attempted'] ?? false),
		'saved' => (bool) ($partyLeaveResult['saved'] ?? false),
		'reason' => (string) ($partyLeaveResult['reason'] ?? ''),
		'httpStatus' => (int) ($partyLeaveResult['httpStatus'] ?? 0),
		'url' => (string) ($partyLeaveResult['url'] ?? ''),
		'partyName' => (string) ($partyLeaveResult['partyName'] ?? ''),
	],
	'storage_money_result' => [
		'attempted' => (bool) ($storageMoneyResult['attempted'] ?? false),
		'reason' => (string) ($storageMoneyResult['reason'] ?? ''),
		'httpStatus' => (int) ($storageMoneyResult['httpStatus'] ?? 0),
		'url' => (string) ($storageMoneyResult['url'] ?? ''),
		'bodyLength' => (int) ($storageMoneyResult['bodyLength'] ?? 0),
		'accountsCount' => is_array($storageMoneyResult['accounts'] ?? null) ? count($storageMoneyResult['accounts']) : 0,
	],
	'storage_equipment_result' => [
		'attempted' => (bool) ($storageEquipmentResult['attempted'] ?? false),
		'reason' => (string) ($storageEquipmentResult['reason'] ?? ''),
		'httpStatus' => (int) ($storageEquipmentResult['httpStatus'] ?? 0),
		'url' => (string) ($storageEquipmentResult['url'] ?? ''),
		'inventoryUrl' => (string) ($storageEquipmentResult['inventoryUrl'] ?? ''),
		'inventoryHttpStatus' => (int) ($storageEquipmentResult['inventoryHttpStatus'] ?? 0),
		'bodyLength' => (int) ($storageEquipmentResult['bodyLength'] ?? 0),
		'equippedCount' => (int) ($storageEquipmentResult['equippedCount'] ?? 0),
		'storageCount' => (int) ($storageEquipmentResult['storageCount'] ?? 0),
	],
	'equipment_sell_result' => [
		'attempted' => (bool) ($equipmentSellResult['attempted'] ?? false),
		'saved' => (bool) ($equipmentSellResult['saved'] ?? false),
		'reason' => (string) ($equipmentSellResult['reason'] ?? ''),
		'httpStatus' => (int) ($equipmentSellResult['httpStatus'] ?? 0),
		'url' => (string) ($equipmentSellResult['url'] ?? ''),
		'itemId' => (string) ($equipmentSellResult['itemId'] ?? ''),
		'price' => (string) ($equipmentSellResult['price'] ?? ''),
		'length' => (string) ($equipmentSellResult['length'] ?? ''),
	],
	'free_starter_pack_result' => [
		'checked' => (bool) ($freeStarterPackResult['checked'] ?? false),
		'found' => (bool) ($freeStarterPackResult['found'] ?? false),
		'claimButtonFound' => (bool) ($freeStarterPackResult['claimButtonFound'] ?? false),
		'source' => (string) ($freeStarterPackResult['source'] ?? ''),
		'openUrl' => (string) ($freeStarterPackResult['openUrl'] ?? ''),
		'claimUrl' => (string) ($freeStarterPackResult['claimUrl'] ?? ''),
		'reason' => (string) ($freeStarterPackResult['reason'] ?? ''),
	],
	'free_starter_pack_open_result' => [
		'attempted' => (bool) ($freeStarterPackOpenResult['attempted'] ?? false),
		'saved' => (bool) ($freeStarterPackOpenResult['saved'] ?? false),
		'reason' => (string) ($freeStarterPackOpenResult['reason'] ?? ''),
		'httpStatus' => (int) ($freeStarterPackOpenResult['httpStatus'] ?? 0),
		'url' => (string) ($freeStarterPackOpenResult['url'] ?? ''),
		'bodyLength' => (int) ($freeStarterPackOpenResult['bodyLength'] ?? 0),
		'found' => (bool) ($freeStarterPackOpenResult['found'] ?? false),
		'claimButtonFound' => (bool) ($freeStarterPackOpenResult['claimButtonFound'] ?? false),
		'claimUrl' => (string) ($freeStarterPackOpenResult['claimUrl'] ?? ''),
	],
	'free_starter_pack_claim_result' => [
		'attempted' => (bool) ($freeStarterPackClaimResult['attempted'] ?? false),
		'saved' => (bool) ($freeStarterPackClaimResult['saved'] ?? false),
		'reason' => (string) ($freeStarterPackClaimResult['reason'] ?? ''),
		'httpStatus' => (int) ($freeStarterPackClaimResult['httpStatus'] ?? 0),
		'url' => (string) ($freeStarterPackClaimResult['url'] ?? ''),
		'claimUrl' => (string) ($freeStarterPackClaimResult['claimUrl'] ?? ''),
	],
	'tutorial_mission_state' => [
		'checked' => (bool) ($tutorialMissionState['checked'] ?? false),
		'hasTutorialBallContainer' => (bool) ($tutorialMissionState['hasTutorialBallContainer'] ?? false),
		'hasMissionDropdown' => (bool) ($tutorialMissionState['hasMissionDropdown'] ?? false),
		'selectedMissionTitle' => (string) ($tutorialMissionState['selectedMissionTitle'] ?? ''),
		'selectedMissionDescription' => (string) ($tutorialMissionState['selectedMissionDescription'] ?? ''),
		'hasInProgressPanel' => (bool) ($tutorialMissionState['hasInProgressPanel'] ?? false),
		'inProgressTitle' => (string) ($tutorialMissionState['inProgressTitle'] ?? ''),
		'inProgressDescription' => (string) ($tutorialMissionState['inProgressDescription'] ?? ''),
		'inProgressSummary' => (string) ($tutorialMissionState['inProgressSummary'] ?? ''),
		'hasRewardMissionForm' => (bool) ($tutorialMissionState['hasRewardMissionForm'] ?? false),
		'rewardActionUrl' => (string) ($tutorialMissionState['rewardActionUrl'] ?? ''),
		'rewardMethod' => (string) ($tutorialMissionState['rewardMethod'] ?? ''),
		'hasSkipOption' => (bool) ($tutorialMissionState['hasSkipOption'] ?? false),
		'skipActionUrl' => (string) ($tutorialMissionState['skipActionUrl'] ?? ''),
		'skipMethod' => (string) ($tutorialMissionState['skipMethod'] ?? ''),
		'availableMissionCount' => (int) ($tutorialMissionState['availableMissionCount'] ?? 0),
		'reason' => (string) ($tutorialMissionState['reason'] ?? ''),
	],
	'tutorial_mission_complete_result' => [
		'attempted' => (bool) ($tutorialMissionCompleteResult['attempted'] ?? false),
		'saved' => (bool) ($tutorialMissionCompleteResult['saved'] ?? false),
		'reason' => (string) ($tutorialMissionCompleteResult['reason'] ?? ''),
		'url' => (string) ($tutorialMissionCompleteResult['url'] ?? ''),
		'method' => (string) ($tutorialMissionCompleteResult['method'] ?? ''),
		'firstHttpStatus' => (int) ($tutorialMissionCompleteResult['firstHttpStatus'] ?? 0),
		'secondHttpStatus' => (int) ($tutorialMissionCompleteResult['secondHttpStatus'] ?? 0),
	],
	'tutorial_mission_skip_result' => [
		'attempted' => (bool) ($tutorialMissionSkipResult['attempted'] ?? false),
		'saved' => (bool) ($tutorialMissionSkipResult['saved'] ?? false),
		'reason' => (string) ($tutorialMissionSkipResult['reason'] ?? ''),
		'url' => (string) ($tutorialMissionSkipResult['url'] ?? ''),
		'method' => (string) ($tutorialMissionSkipResult['method'] ?? ''),
		'httpStatus' => (int) ($tutorialMissionSkipResult['httpStatus'] ?? 0),
	],
	'auction_market_result' => [
		'attempted' => (bool) ($auctionMarketResult['attempted'] ?? false),
		'saved' => (bool) ($auctionMarketResult['saved'] ?? false),
		'reason' => (string) ($auctionMarketResult['reason'] ?? ''),
		'httpStatus' => (int) ($auctionMarketResult['httpStatus'] ?? 0),
		'url' => (string) ($auctionMarketResult['url'] ?? ''),
		'bodyLength' => (int) ($auctionMarketResult['bodyLength'] ?? 0),
		'itemsCount' => (int) ($auctionMarketResult['itemsCount'] ?? 0),
	],
	'auction_bid_result' => [
		'attempted' => (bool) ($auctionBidResult['attempted'] ?? false),
		'saved' => (bool) ($auctionBidResult['saved'] ?? false),
		'reason' => (string) ($auctionBidResult['reason'] ?? ''),
		'httpStatus' => (int) ($auctionBidResult['httpStatus'] ?? 0),
		'url' => (string) ($auctionBidResult['url'] ?? ''),
		'auctionId' => (string) ($auctionBidResult['auctionId'] ?? ''),
		'price' => (string) ($auctionBidResult['price'] ?? ''),
	],
	'article_inspect_result' => [
		'attempted' => (bool) ($articleInspectResult['attempted'] ?? false),
		'saved' => (bool) ($articleInspectResult['saved'] ?? false),
		'reason' => (string) ($articleInspectResult['reason'] ?? ''),
		'httpStatus' => (int) ($articleInspectResult['httpStatus'] ?? 0),
		'url' => (string) ($articleInspectResult['url'] ?? ''),
		'articleId' => (string) ($articleInspectResult['articleId'] ?? ''),
		'articleTitle' => (string) ($articleInspectResult['articleTitle'] ?? ''),
		'voteDetected' => (bool) ($articleInspectResult['voteDetected'] ?? false),
		'subscribeDetected' => (bool) ($articleInspectResult['subscribeDetected'] ?? false),
	],
	'article_vote_result' => [
		'attempted' => (bool) ($articleVoteResult['attempted'] ?? false),
		'saved' => (bool) ($articleVoteResult['saved'] ?? false),
		'reason' => (string) ($articleVoteResult['reason'] ?? ''),
		'httpStatus' => (int) ($articleVoteResult['httpStatus'] ?? 0),
		'url' => (string) ($articleVoteResult['url'] ?? ''),
		'articleId' => (string) ($articleVoteResult['articleId'] ?? ''),
	],
	'article_subscribe_result' => [
		'attempted' => (bool) ($articleSubscribeResult['attempted'] ?? false),
		'saved' => (bool) ($articleSubscribeResult['saved'] ?? false),
		'reason' => (string) ($articleSubscribeResult['reason'] ?? ''),
		'httpStatus' => (int) ($articleSubscribeResult['httpStatus'] ?? 0),
		'url' => (string) ($articleSubscribeResult['url'] ?? ''),
		'articleId' => (string) ($articleSubscribeResult['articleId'] ?? ''),
	],
	'elections_inspect_result' => [
		'attempted' => (bool) ($electionsInspectResult['attempted'] ?? false),
		'saved' => (bool) ($electionsInspectResult['saved'] ?? false),
		'reason' => (string) ($electionsInspectResult['reason'] ?? ''),
		'httpStatus' => (int) ($electionsInspectResult['httpStatus'] ?? 0),
		'url' => (string) ($electionsInspectResult['url'] ?? ''),
		'pageTitle' => (string) ($electionsInspectResult['pageTitle'] ?? ''),
		'candidateActionUrl' => (string) ($electionsInspectResult['candidateActionUrl'] ?? ''),
		'optionsCount' => is_array($electionsInspectResult['options'] ?? null) ? count((array) $electionsInspectResult['options']) : 0,
	],
	'elections_candidate_result' => [
		'attempted' => (bool) ($electionsCandidateResult['attempted'] ?? false),
		'saved' => (bool) ($electionsCandidateResult['saved'] ?? false),
		'reason' => (string) ($electionsCandidateResult['reason'] ?? ''),
		'httpStatus' => (int) ($electionsCandidateResult['httpStatus'] ?? 0),
		'url' => (string) ($electionsCandidateResult['url'] ?? ''),
		'presentation' => (string) ($electionsCandidateResult['presentation'] ?? ''),
	],
	'military_unit_inspect_result' => [
		'attempted' => (bool) ($militaryUnitInspectResult['attempted'] ?? false),
		'saved' => (bool) ($militaryUnitInspectResult['saved'] ?? false),
		'reason' => (string) ($militaryUnitInspectResult['reason'] ?? ''),
		'httpStatus' => (int) ($militaryUnitInspectResult['httpStatus'] ?? 0),
		'url' => (string) ($militaryUnitInspectResult['url'] ?? ''),
		'unitName' => (string) ($militaryUnitInspectResult['unitName'] ?? ''),
		'applyDetected' => (bool) ($militaryUnitInspectResult['applyDetected'] ?? false),
		'optionsCount' => is_array($militaryUnitInspectResult['options'] ?? null) ? count((array) $militaryUnitInspectResult['options']) : 0,
	],
	'military_unit_apply_result' => [
		'attempted' => (bool) ($militaryUnitApplyResult['attempted'] ?? false),
		'saved' => (bool) ($militaryUnitApplyResult['saved'] ?? false),
		'reason' => (string) ($militaryUnitApplyResult['reason'] ?? ''),
		'httpStatus' => (int) ($militaryUnitApplyResult['httpStatus'] ?? 0),
		'url' => (string) ($militaryUnitApplyResult['url'] ?? ''),
		'unitId' => (string) ($militaryUnitApplyResult['unitId'] ?? ''),
	],
	'product_market_result' => [
		'attempted' => (bool) ($productMarketResult['attempted'] ?? false),
		'reason' => (string) ($productMarketResult['reason'] ?? ''),
		'httpStatus' => (int) ($productMarketResult['httpStatus'] ?? 0),
		'url' => (string) ($productMarketResult['url'] ?? ''),
		'bodyLength' => (int) ($productMarketResult['bodyLength'] ?? 0),
	],
	'product_market_offers_result' => [
		'attempted' => (bool) ($productMarketOffersResult['attempted'] ?? false),
		'reason' => (string) ($productMarketOffersResult['reason'] ?? ''),
		'httpStatus' => (int) ($productMarketOffersResult['httpStatus'] ?? 0),
		'url' => (string) ($productMarketOffersResult['url'] ?? ''),
		'bodyLength' => (int) ($productMarketOffersResult['bodyLength'] ?? 0),
		'type' => (string) ($productMarketOffersResult['type'] ?? ''),
		'quality' => (string) ($productMarketOffersResult['quality'] ?? ''),
		'countryId' => (string) ($productMarketOffersResult['countryId'] ?? ''),
		'page' => (string) ($productMarketOffersResult['page'] ?? ''),
		'itemsCount' => is_array($productMarketOffersResult['offers'] ?? null) ? count($productMarketOffersResult['offers']) : 0,
	],
	'product_market_buy_result' => [
		'attempted' => (bool) ($productMarketBuyResult['attempted'] ?? false),
		'reason' => (string) ($productMarketBuyResult['reason'] ?? ''),
		'httpStatus' => (int) ($productMarketBuyResult['httpStatus'] ?? 0),
		'url' => (string) ($productMarketBuyResult['url'] ?? ''),
		'offerId' => (string) ($productMarketBuyResult['offerId'] ?? ''),
		'quantity' => (string) ($productMarketBuyResult['quantity'] ?? ''),
		'currencyId' => (string) ($productMarketBuyResult['currencyId'] ?? ''),
	],
	'bandit_blue_open_result' => [
		'attempted' => (bool) ($banditBlueOpenResult['attempted'] ?? false),
		'saved' => (bool) ($banditBlueOpenResult['saved'] ?? false),
		'reason' => (string) ($banditBlueOpenResult['reason'] ?? ''),
		'gameRoomHttpStatus' => (int) ($banditBlueOpenResult['gameRoomHttpStatus'] ?? 0),
		'banditHttpStatus' => (int) ($banditBlueOpenResult['banditHttpStatus'] ?? 0),
		'url' => (string) ($banditBlueOpenResult['url'] ?? ''),
		'containsHandlePlay' => (bool) ($banditBlueOpenResult['containsHandlePlay'] ?? false),
	],
	'bandit_blue_run_result' => [
		'attempted' => (bool) ($banditBlueRunResult['attempted'] ?? false),
		'saved' => (bool) ($banditBlueRunResult['saved'] ?? false),
		'reason' => (string) ($banditBlueRunResult['reason'] ?? ''),
		'playHttpStatus' => (int) ($banditBlueRunResult['playHttpStatus'] ?? 0),
		'rewardHttpStatus' => (int) ($banditBlueRunResult['rewardHttpStatus'] ?? 0),
		'url' => (string) ($banditBlueRunResult['url'] ?? ''),
		'rewardUrl' => (string) ($banditBlueRunResult['rewardUrl'] ?? ''),
		'runId' => (string) ($banditBlueRunResult['runId'] ?? ''),
	],
	'battle_action_attempted' => $battleActionAttempted,
	'battle_action_result' => $battleActionResult,
	'battle_inspect_result' => [
		'attempted' => (bool) ($battleInspectResult['attempted'] ?? false),
		'saved' => (bool) ($battleInspectResult['saved'] ?? false),
		'reason' => (string) ($battleInspectResult['reason'] ?? ''),
		'httpStatus' => (int) ($battleInspectResult['httpStatus'] ?? 0),
		'battleUrl' => (string) ($battleInspectResult['battleUrl'] ?? ''),
		'battleTitle' => (string) ($battleInspectResult['battleTitle'] ?? ''),
	],
	'battles_result' => [
		'attempted' => (bool) ($battlesResult['attempted'] ?? false),
		'reason' => (string) ($battlesResult['reason'] ?? ''),
		'httpStatus' => (int) ($battlesResult['httpStatus'] ?? 0),
		'url' => (string) ($battlesResult['url'] ?? ''),
		'count' => is_array($battlesResult['items'] ?? null) ? count($battlesResult['items']) : 0,
		'bodyLength' => (int) ($battlesResult['bodyLength'] ?? 0),
		'pagesScanned' => (int) ($battlesResult['pagesScanned'] ?? 0),
		'practiceFound' => (bool) ($battlesResult['practiceFound'] ?? false),
	],
	'cookie_file' => $cookieFile,
	'last_url' => $step3['effectiveUrl'],
	'last_status' => $step3['statusCode'],
];

function curlRequest($ch, string $url, array $extraOptions = []): array
{
	$options = [
		CURLOPT_URL => $url,
		CURLOPT_POST => false,
		CURLOPT_HTTPGET => true,
	];

	foreach ($extraOptions as $key => $value) {
		$options[$key] = $value;
	}

	curl_setopt_array($ch, $options);
	$body = curl_exec($ch);

	return [
		'url' => $url,
		'statusCode' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
		'effectiveUrl' => (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
		'contentType' => (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE),
		'totalTime' => (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME),
		'errno' => curl_errno($ch),
		'error' => curl_error($ch),
		'body' => is_string($body) ? $body : '',
	];
}

function submitLogout($ch, string $refererUrl, array $headers): array
{
	$base = $refererUrl !== '' ? $refererUrl : serverUrl('index.html');
	$targetUrls = [
		resolveUrl($base, 'logout.html'),
		resolveUrl($base, 'logout'),
		resolveUrl($base, 'index.html?logout=1'),
	];
	$targetUrls = array_values(array_unique(array_filter($targetUrls, static function ($url): bool {
		return is_string($url) && trim($url) !== '';
	})));

	$postHeaders = array_merge($headers, [
		'Referer: ' . $base,
		'Origin: ' . rtrim(serverUrl(''), '/'),
	]);

	$lastStep = null;
	$lastUrl = '';
	foreach ($targetUrls as $targetUrl) {
		$step = curlRequest($ch, $targetUrl, [
			CURLOPT_POST => false,
			CURLOPT_HTTPGET => true,
			CURLOPT_HTTPHEADER => $postHeaders,
		]);

		$lastStep = $step;
		$lastUrl = $targetUrl;
		$status = (int) ($step['statusCode'] ?? 0);
		if ($status >= 200 && $status < 400 && $status !== 404) {
			break;
		}
	}

	if (!is_array($lastStep)) {
		$lastStep = [
			'errno' => 0,
			'error' => '',
			'statusCode' => 0,
			'effectiveUrl' => $lastUrl,
			'body' => '',
		];
	}

	$statusCode = (int) ($lastStep['statusCode'] ?? 0);
	$errno = (int) ($lastStep['errno'] ?? 0);
	$effectiveUrl = (string) (($lastStep['effectiveUrl'] ?? '') !== '' ? $lastStep['effectiveUrl'] : $lastUrl);

	$reason = 'logout-rejected';
	$saved = false;
	if ($errno !== 0) {
		$reason = 'logout-request-error';
	} elseif ($statusCode === 404) {
		$reason = 'logout-endpoint-not-found';
	} elseif ($statusCode >= 200 && $statusCode < 400) {
		$reason = 'logout-submitted';
		$saved = true;
	} else {
		$reason = 'logout-http-error';
	}

	return [
		'attempted' => true,
		'saved' => $saved,
		'reason' => $reason,
		'url' => $effectiveUrl,
		'httpStatus' => $statusCode,
		'error' => (string) ($lastStep['error'] ?? ''),
	];
}

function clearAllLocalCookieFiles(string $cookieDir): int
{
	if (!is_dir($cookieDir)) {
		return 0;
	}

	$patterns = [
		rtrim($cookieDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*cookie*.txt',
	];
	$deleted = 0;
	$seen = [];
	foreach ($patterns as $pattern) {
		$matches = glob($pattern);
		if (!is_array($matches)) {
			continue;
		}
		foreach ($matches as $candidate) {
			if (!is_string($candidate) || $candidate === '' || isset($seen[$candidate])) {
				continue;
			}
			$seen[$candidate] = true;
			if (is_file($candidate) && @unlink($candidate)) {
				$deleted++;
			}
		}
	}

	return $deleted;
}

function openBanditBlueGame($ch, string $refererUrl, array $headers, string $gameRoomUrl): array
{
	$gameRoomStep = curlRequest($ch, $gameRoomUrl, [
		CURLOPT_POST => false,
		CURLOPT_HTTPGET => true,
		CURLOPT_HTTPHEADER => $headers,
	]);

	$gameRoomEffectiveUrl = (string) ($gameRoomStep['effectiveUrl'] ?: $gameRoomUrl);
	$banditUrl = resolveUrl($gameRoomEffectiveUrl, 'bandit?automatType=BLUE');
	$banditHeaders = array_merge($headers, [
		'Referer: ' . $gameRoomEffectiveUrl,
		'X-Requested-With: XMLHttpRequest',
	]);
	$banditStep = curlRequest($ch, $banditUrl, [
		CURLOPT_POST => false,
		CURLOPT_HTTPGET => true,
		CURLOPT_HTTPHEADER => $banditHeaders,
	]);

	$gameRoomStatus = (int) ($gameRoomStep['statusCode'] ?? 0);
	$banditStatus = (int) ($banditStep['statusCode'] ?? 0);
	$banditBody = (string) ($banditStep['body'] ?? '');
	$containsHandle = stripos($banditBody, 'banditHandlePlay') !== false;
	$looksNotLogged = stripos($banditBody, 'Not logged in') !== false;

	$reason = 'bandit-blue-open-rejected';
	$saved = false;
	if ((int) ($gameRoomStep['errno'] ?? 0) !== 0 || (int) ($banditStep['errno'] ?? 0) !== 0) {
		$reason = 'bandit-blue-open-request-error';
	} elseif ($banditStatus === 404) {
		$reason = 'bandit-blue-open-endpoint-not-found';
	} elseif ($looksNotLogged) {
		$reason = 'bandit-blue-open-not-logged';
	} elseif ($banditStatus >= 200 && $banditStatus < 400 && $containsHandle) {
		$reason = 'bandit-blue-opened';
		$saved = true;
	} elseif ($banditStatus >= 200 && $banditStatus < 400) {
		$reason = 'bandit-blue-opened-without-handle';
	} else {
		$reason = 'bandit-blue-open-http-error';
	}

	return [
		'attempted' => true,
		'saved' => $saved,
		'reason' => $reason,
		'gameRoomHttpStatus' => $gameRoomStatus,
		'banditHttpStatus' => $banditStatus,
		'url' => (string) ($banditStep['effectiveUrl'] ?: $banditUrl),
		'containsHandlePlay' => $containsHandle,
		'error' => trim((string) (($banditStep['error'] ?? '') !== '' ? $banditStep['error'] : ($gameRoomStep['error'] ?? ''))),
	];
}

function runBanditBlueRound($ch, string $refererUrl, array $headers, string $gameRoomUrl): array
{
	$openResult = openBanditBlueGame($ch, $refererUrl, $headers, $gameRoomUrl);
	$playUrl = resolveUrl($gameRoomUrl, 'bandit/play?automatType=BLUE');
	$playHeaders = array_merge($headers, [
		'Referer: ' . $gameRoomUrl,
		'Origin: ' . rtrim(serverUrl(''), '/'),
		'X-Requested-With: XMLHttpRequest',
		'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
	]);

	$playStep = curlRequest($ch, $playUrl, [
		CURLOPT_POST => true,
		CURLOPT_HTTPGET => false,
		CURLOPT_POSTFIELDS => '',
		CURLOPT_HTTPHEADER => $playHeaders,
	]);

	$playStatus = (int) ($playStep['statusCode'] ?? 0);
	$playBody = (string) ($playStep['body'] ?? '');
	$playJson = json_decode($playBody, true);
	$runId = '';
	if (is_array($playJson) && isset($playJson['id'])) {
		$runId = trim((string) $playJson['id']);
	}

	$rewardUrl = '';
	$rewardStep = [
		'statusCode' => 0,
		'effectiveUrl' => '',
		'body' => '',
		'error' => '',
		'errno' => 0,
	];
	if ($runId !== '') {
		$rewardUrl = resolveUrl($gameRoomUrl, 'bandit/reward?id=' . rawurlencode($runId));
		$rewardHeaders = array_merge($headers, [
			'Referer: ' . $gameRoomUrl,
			'Origin: ' . rtrim(serverUrl(''), '/'),
			'X-Requested-With: XMLHttpRequest',
		]);
		$rewardStep = curlRequest($ch, $rewardUrl, [
			CURLOPT_POST => true,
			CURLOPT_HTTPGET => false,
			CURLOPT_POSTFIELDS => '',
			CURLOPT_HTTPHEADER => $rewardHeaders,
		]);
	}

	$rewardStatus = (int) ($rewardStep['statusCode'] ?? 0);
	$rewardBody = (string) ($rewardStep['body'] ?? '');
	$playNotLogged = stripos($playBody, 'Not logged in') !== false;

	$reason = 'bandit-blue-run-rejected';
	$saved = false;
	if (!empty($openResult['reason']) && in_array((string) $openResult['reason'], ['bandit-blue-open-not-logged', 'bandit-blue-open-request-error', 'bandit-blue-open-endpoint-not-found'], true)) {
		$reason = 'bandit-blue-run-open-failed';
	} elseif ((int) ($playStep['errno'] ?? 0) !== 0) {
		$reason = 'bandit-blue-play-request-error';
	} elseif ($playStatus === 404) {
		$reason = 'bandit-blue-play-endpoint-not-found';
	} elseif ($playNotLogged) {
		$reason = 'bandit-blue-play-not-logged';
	} elseif ($playStatus < 200 || $playStatus >= 400) {
		$reason = 'bandit-blue-play-http-error';
	} elseif ($runId === '') {
		$reason = 'bandit-blue-play-id-missing';
	} elseif ((int) ($rewardStep['errno'] ?? 0) !== 0) {
		$reason = 'bandit-blue-reward-request-error';
	} elseif ($rewardStatus === 404) {
		$reason = 'bandit-blue-reward-endpoint-not-found';
	} elseif ($rewardStatus < 200 || $rewardStatus >= 400) {
		$reason = 'bandit-blue-reward-http-error';
	} else {
		$reason = 'bandit-blue-run-submitted';
		$saved = true;
	}

	return [
		'attempted' => true,
		'saved' => $saved,
		'reason' => $reason,
		'playHttpStatus' => $playStatus,
		'rewardHttpStatus' => $rewardStatus,
		'url' => (string) ($playStep['effectiveUrl'] ?: $playUrl),
		'rewardUrl' => (string) (($rewardStep['effectiveUrl'] ?? '') !== '' ? $rewardStep['effectiveUrl'] : $rewardUrl),
		'runId' => $runId,
		'rewardSnippet' => trim(substr(compactNodeText($rewardBody), 0, 220)),
		'error' => trim((string) (($rewardStep['error'] ?? '') !== '' ? $rewardStep['error'] : ($playStep['error'] ?? ''))),
	];
}

function sameEsimUserName(string $actual, string $expected): bool
{
	$normalize = static function (string $value): string {
		$value = strtolower(trim($value));
		$value = preg_replace('/\s+/', '', $value);
		return is_string($value) ? $value : '';
	};

	$actualNorm = $normalize($actual);
	$expectedNorm = $normalize($expected);
	if ($actualNorm === '' || $expectedNorm === '') {
		return false;
	}

	return $actualNorm === $expectedNorm;
}

function submitFreeStarterPackClaim($ch, string $refererUrl, array $headers, string $claimUrl): array
{
	$claimUrl = trim($claimUrl);
	if ($claimUrl === '') {
		return [
			'attempted' => true,
			'saved' => false,
			'reason' => 'free-starter-pack-claim-url-missing',
			'httpStatus' => 0,
			'url' => '',
			'claimUrl' => '',
			'responseSnippet' => '',
			'error' => '',
		];
	}

	$postPayloads = [
		[],
		['action' => 'claim'],
		['claim' => '1'],
	];
	$bestStep = null;
	foreach ($postPayloads as $payload) {
		$step = curlRequest($ch, $claimUrl, [
			CURLOPT_POST => true,
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_REFERER => $refererUrl,
			CURLOPT_POSTFIELDS => http_build_query($payload),
		]);
		$bestStep = $step;
		$body = (string) ($step['body'] ?? '');
		$statusCode = (int) ($step['statusCode'] ?? 0);
		if ($step['errno'] === 0 && $statusCode >= 200 && $statusCode < 400 && trim($body) !== '') {
			break;
		}
	}

	if (!is_array($bestStep)) {
		$bestStep = [
			'errno' => 0,
			'error' => '',
			'statusCode' => 0,
			'effectiveUrl' => $claimUrl,
			'body' => '',
		];
	}

	$responseBody = (string) ($bestStep['body'] ?? '');
	$responseLower = strtolower($responseBody);
	$statusCode = (int) ($bestStep['statusCode'] ?? 0);
	$errno = (int) ($bestStep['errno'] ?? 0);
	$error = (string) ($bestStep['error'] ?? '');
	$effectiveUrl = (string) ($bestStep['effectiveUrl'] ?: $claimUrl);

	$mentionsStarter = str_contains($responseLower, 'free starter pack') || str_contains($responseLower, 'starter pack');
	$isSuccess = preg_match('/(claimed|received|congrat|reward added|has been added)/i', $responseBody) === 1;
	$isAlreadyClaimed = preg_match('/(already claimed|already received|already used|not available anymore)/i', $responseBody) === 1;
	$looksBlocked = preg_match('/(not logged|login|required|captcha|forbidden|access denied|error)/i', $responseBody) === 1;

	$reason = 'free-starter-pack-claim-unknown';
	$saved = false;
	if ($errno !== 0) {
		$reason = 'free-starter-pack-claim-request-error';
	} elseif ($statusCode < 200 || $statusCode >= 400) {
		$reason = 'free-starter-pack-claim-http-error';
	} elseif ($isSuccess) {
		$reason = 'free-starter-pack-claim-success';
		$saved = true;
	} elseif ($isAlreadyClaimed) {
		$reason = 'free-starter-pack-already-claimed';
		$saved = true;
	} elseif ($looksBlocked) {
		$reason = 'free-starter-pack-claim-blocked';
	} elseif ($mentionsStarter && trim($responseBody) !== '') {
		$reason = 'free-starter-pack-claim-processed';
		$saved = true;
	}

	return [
		'attempted' => true,
		'saved' => $saved,
		'reason' => $reason,
		'httpStatus' => $statusCode,
		'url' => $effectiveUrl,
		'claimUrl' => $claimUrl,
		'responseSnippet' => trim(substr(preg_replace('/\s+/', ' ', strip_tags($responseBody)), 0, 220)),
		'error' => $error,
	];
}

function submitTutorialMissionComplete($ch, string $refererUrl, array $headers, string $completeUrl, string $method = 'POST', array $baseFields = []): array
{
	$completeUrl = trim($completeUrl);
	if ($completeUrl === '') {
		$completeUrl = serverUrl('betaMissions.html');
	}

	$method = strtoupper(trim($method));
	if (!in_array($method, ['POST', 'GET'], true)) {
		$method = 'POST';
	}

	$targetUrls = [];
	$targetUrls[] = $completeUrl;
	$completeUrlWithoutAction = preg_replace('/([?&])action=(COMPLETE|START)(&?)/i', '$1', $completeUrl);
	$completeUrlWithoutAction = is_string($completeUrlWithoutAction) ? $completeUrlWithoutAction : $completeUrl;
	$completeUrlWithoutAction = rtrim(str_replace(['?&', '&&'], ['?', '&'], $completeUrlWithoutAction), '?&');
	if ($completeUrlWithoutAction !== '') {
		$targetUrls[] = $completeUrlWithoutAction;
	}
	$targetUrls[] = resolveUrl($refererUrl !== '' ? $refererUrl : serverUrl('index.html'), 'betaMissions.html');
	$targetUrls = array_values(array_unique(array_filter($targetUrls, static function ($url): bool {
		return is_string($url) && trim($url) !== '';
	})));

	$requestHeaders = array_merge($headers, [
		'Referer: ' . ($refererUrl !== '' ? $refererUrl : serverUrl('index.html')),
		'Origin: ' . rtrim(serverUrl(''), '/'),
		'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
	]);

	$firstStep = null;
	$secondStep = null;
	$effectiveTargetUrl = $completeUrl;
	$basePayload = [];
	foreach ($baseFields as $k => $v) {
		$key = trim((string) $k);
		if ($key === '') {
			continue;
		}
		$basePayload[$key] = is_scalar($v) ? (string) $v : '';
	}

	foreach ($targetUrls as $targetUrl) {
		$payloadComplete = $basePayload;
		$payloadComplete['action'] = 'COMPLETE';
		$payloadStart = $basePayload;
		$payloadStart['action'] = 'START';

		if ($method === 'GET') {
			$firstUrl = $targetUrl . (str_contains($targetUrl, '?') ? '&' : '?') . http_build_query($payloadComplete);
			$secondUrl = $targetUrl . (str_contains($targetUrl, '?') ? '&' : '?') . http_build_query($payloadStart);
			$firstTry = curlRequest($ch, $firstUrl, [
				CURLOPT_POST => false,
				CURLOPT_HTTPGET => true,
				CURLOPT_HTTPHEADER => $requestHeaders,
			]);
			$secondTry = curlRequest($ch, $secondUrl, [
				CURLOPT_POST => false,
				CURLOPT_HTTPGET => true,
				CURLOPT_HTTPHEADER => $requestHeaders,
			]);
		} else {
			$firstTry = curlRequest($ch, $targetUrl, [
				CURLOPT_POST => true,
				CURLOPT_HTTPGET => false,
				CURLOPT_POSTFIELDS => http_build_query($payloadComplete),
				CURLOPT_HTTPHEADER => $requestHeaders,
			]);
			$secondTry = curlRequest($ch, $targetUrl, [
				CURLOPT_POST => true,
				CURLOPT_HTTPGET => false,
				CURLOPT_POSTFIELDS => http_build_query($payloadStart),
				CURLOPT_HTTPHEADER => $requestHeaders,
			]);
		}

		$firstStep = $firstTry;
		$secondStep = $secondTry;
		$effectiveTargetUrl = $targetUrl;

		$firstStatusTry = (int) ($firstTry['statusCode'] ?? 0);
		$secondStatusTry = (int) ($secondTry['statusCode'] ?? 0);
		$firstNot404 = $firstStatusTry !== 404;
		$secondNot404 = $secondStatusTry !== 404;
		if ($firstNot404 && $secondNot404) {
			break;
		}
	}

	if (!is_array($firstStep) || !is_array($secondStep)) {
		$firstStep = [
			'statusCode' => 0,
			'errno' => 0,
			'body' => '',
			'error' => '',
			'effectiveUrl' => $effectiveTargetUrl,
		];
		$secondStep = $firstStep;
	}

	$firstStatus = (int) ($firstStep['statusCode'] ?? 0);
	$secondStatus = (int) ($secondStep['statusCode'] ?? 0);
	$firstOk = (int) ($firstStep['errno'] ?? 0) === 0 && $firstStatus >= 200 && $firstStatus < 400;
	$secondOk = (int) ($secondStep['errno'] ?? 0) === 0 && $secondStatus >= 200 && $secondStatus < 400;

	$firstBody = (string) ($firstStep['body'] ?? '');
	$secondBody = (string) ($secondStep['body'] ?? '');
	$lastError = trim((string) ($secondStep['error'] ?? ''));
	if ($lastError === '') {
		$lastError = trim((string) ($firstStep['error'] ?? ''));
	}

	$reason = 'tutorial-mission-complete-start-rejected';
	if ((int) ($firstStep['errno'] ?? 0) !== 0 || (int) ($secondStep['errno'] ?? 0) !== 0) {
		$reason = 'tutorial-mission-complete-request-error';
	} elseif ($firstStatus === 404 || $secondStatus === 404) {
		$reason = 'tutorial-mission-endpoint-not-found';
	} elseif (($firstStatus < 200 || $firstStatus >= 400) || ($secondStatus < 200 || $secondStatus >= 400)) {
		$reason = 'tutorial-mission-complete-http-error';
	} elseif ($firstOk && $secondOk) {
		$reason = 'tutorial-mission-complete-and-start-submitted';
	}

	return [
		'attempted' => true,
		'saved' => $firstOk && $secondOk,
		'reason' => $reason,
		'url' => (string) (($secondStep['effectiveUrl'] ?? '') !== '' ? $secondStep['effectiveUrl'] : $effectiveTargetUrl),
		'method' => $method,
		'firstHttpStatus' => $firstStatus,
		'secondHttpStatus' => $secondStatus,
		'firstSnippet' => trim(substr(compactNodeText($firstBody), 0, 220)),
		'secondSnippet' => trim(substr(compactNodeText($secondBody), 0, 220)),
		'error' => $lastError,
	];
}

function submitTutorialMissionSkip($ch, string $refererUrl, array $headers, string $skipUrl, string $method = 'POST', array $baseFields = []): array
{
	$skipUrl = trim($skipUrl);
	if ($skipUrl === '') {
		$skipUrl = serverUrl('betaMissions.html');
	}

	$method = strtoupper(trim($method));
	if (!in_array($method, ['POST', 'GET'], true)) {
		$method = 'POST';
	}

	$targetUrls = [];
	$targetUrls[] = $skipUrl;
	$skipUrlWithoutAction = preg_replace('/([?&])action=SKIP(&?)/i', '$1', $skipUrl);
	$skipUrlWithoutAction = is_string($skipUrlWithoutAction) ? $skipUrlWithoutAction : $skipUrl;
	$skipUrlWithoutAction = rtrim(str_replace(['?&', '&&'], ['?', '&'], $skipUrlWithoutAction), '?&');
	if ($skipUrlWithoutAction !== '') {
		$targetUrls[] = $skipUrlWithoutAction;
	}
	$targetUrls[] = resolveUrl($refererUrl !== '' ? $refererUrl : serverUrl('index.html'), 'betaMissions.html');
	$targetUrls = array_values(array_unique(array_filter($targetUrls, static function ($url): bool {
		return is_string($url) && trim($url) !== '';
	})));

	$requestHeaders = array_merge($headers, [
		'Referer: ' . ($refererUrl !== '' ? $refererUrl : serverUrl('index.html')),
		'Origin: ' . rtrim(serverUrl(''), '/'),
		'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
	]);

	$lastStep = null;
	$effectiveTargetUrl = $skipUrl;
	$basePayload = [];
	foreach ($baseFields as $k => $v) {
		$key = trim((string) $k);
		if ($key === '') {
			continue;
		}
		$basePayload[$key] = is_scalar($v) ? (string) $v : '';
	}

	foreach ($targetUrls as $targetUrl) {
		$payloadSkip = $basePayload;
		$payloadSkip['action'] = 'SKIP';

		if ($method === 'GET') {
			$targetRequestUrl = $targetUrl . (str_contains($targetUrl, '?') ? '&' : '?') . http_build_query($payloadSkip);
			$step = curlRequest($ch, $targetRequestUrl, [
				CURLOPT_POST => false,
				CURLOPT_HTTPGET => true,
				CURLOPT_HTTPHEADER => $requestHeaders,
			]);
		} else {
			$step = curlRequest($ch, $targetUrl, [
				CURLOPT_POST => true,
				CURLOPT_HTTPGET => false,
				CURLOPT_POSTFIELDS => http_build_query($payloadSkip),
				CURLOPT_HTTPHEADER => $requestHeaders,
			]);
		}

		$lastStep = $step;
		$effectiveTargetUrl = $targetUrl;
		$statusTry = (int) ($step['statusCode'] ?? 0);
		if ($statusTry !== 404) {
			break;
		}
	}

	if (!is_array($lastStep)) {
		$lastStep = [
			'statusCode' => 0,
			'errno' => 0,
			'body' => '',
			'error' => '',
			'effectiveUrl' => $effectiveTargetUrl,
		];
	}

	$status = (int) ($lastStep['statusCode'] ?? 0);
	$ok = (int) ($lastStep['errno'] ?? 0) === 0 && $status >= 200 && $status < 400;
	$body = (string) ($lastStep['body'] ?? '');
	$error = trim((string) ($lastStep['error'] ?? ''));

	$reason = 'tutorial-mission-skip-rejected';
	if ((int) ($lastStep['errno'] ?? 0) !== 0) {
		$reason = 'tutorial-mission-skip-request-error';
	} elseif ($status === 404) {
		$reason = 'tutorial-mission-skip-endpoint-not-found';
	} elseif ($status < 200 || $status >= 400) {
		$reason = 'tutorial-mission-skip-http-error';
	} elseif ($ok) {
		$reason = 'tutorial-mission-skip-submitted';
	}

	return [
		'attempted' => true,
		'saved' => $ok,
		'reason' => $reason,
		'url' => (string) (($lastStep['effectiveUrl'] ?? '') !== '' ? $lastStep['effectiveUrl'] : $effectiveTargetUrl),
		'method' => $method,
		'httpStatus' => $status,
		'responseSnippet' => trim(substr(compactNodeText($body), 0, 220)),
		'error' => $error,
	];
}

function extractTutorialMissionStateFromHtml(string $html, string $baseUrl): array
{
	$normalizedBaseUrl = $baseUrl !== '' ? $baseUrl : serverUrl('index.html');
	$result = [
		'checked' => false,
		'hasTutorialBallContainer' => false,
		'hasMissionDropdown' => false,
		'hasInProgressPanel' => false,
		'inProgressTitle' => '',
		'inProgressDescription' => '',
		'inProgressSummary' => '',
		'hasRewardMissionForm' => false,
		'rewardActionUrl' => resolveUrl($normalizedBaseUrl, 'betaMissions.html'),
		'rewardMethod' => 'POST',
		'rewardFields' => [],
		'hasSkipOption' => false,
		'skipActionUrl' => '',
		'skipMethod' => 'POST',
		'skipFields' => [],
		'availableMissionCount' => 0,
		'reason' => 'empty-html',
	];

	if (trim($html) === '') {
		return $result;
	}

	$result['checked'] = true;
	$result['reason'] = 'not-detected';

	$dom = new DOMDocument();
	libxml_use_internal_errors(true);
	$dom->loadHTML($html);
	libxml_clear_errors();
	$xpath = new DOMXPath($dom);

	$tutorialNode = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " tutorialBallContainer ")]')->item(0);
	if ($tutorialNode instanceof DOMElement) {
		$result['hasTutorialBallContainer'] = true;
		$result['reason'] = 'tutorial-ball-container-detected';
	}

	$missionDropdownNode = $xpath->query('//*[@id="missionDropdown"]')->item(0);
	if ($missionDropdownNode instanceof DOMElement) {
		$result['hasMissionDropdown'] = true;
		$missionCandidates = $xpath->query('.//*[self::li or self::a or self::option][normalize-space(string(.))!=""]', $missionDropdownNode);
		if ($missionCandidates instanceof DOMNodeList) {
			$result['availableMissionCount'] = (int) $missionCandidates->length;
		}

		$selectedMissionNode = $xpath->query('.//*[self::option[@selected] or self::li[contains(concat(" ", normalize-space(@class), " "), " active ")] or self::a[contains(concat(" ", normalize-space(@class), " "), " active ")]][1]', $missionDropdownNode)->item(0);
		if (!($selectedMissionNode instanceof DOMElement)) {
			$selectedMissionNode = $xpath->query('.//*[self::option or self::li or self::a][normalize-space(string(.))!=""][1]', $missionDropdownNode)->item(0);
		}
		if ($selectedMissionNode instanceof DOMElement) {
			$result['selectedMissionTitle'] = compactNodeText((string) $selectedMissionNode->textContent);
			$selectedDescription = trim((string) $selectedMissionNode->getAttribute('data-description'));
			if ($selectedDescription === '') {
				$selectedDescription = trim((string) $selectedMissionNode->getAttribute('title'));
			}
			if ($selectedDescription !== '') {
				$result['selectedMissionDescription'] = trim(substr(compactNodeText(html_entity_decode(strip_tags($selectedDescription), ENT_QUOTES | ENT_HTML5, 'UTF-8')), 0, 320));
			}
		}
	}

	$inProgressNode = $xpath->query('//*[@id="inProgressPanel"]')->item(0);
	if ($inProgressNode instanceof DOMElement) {
		$result['hasInProgressPanel'] = true;
		$titleNode = $xpath->query('.//*[self::h1 or self::h2 or self::h3 or self::h4 or self::strong][normalize-space(string(.))!=""][1]', $inProgressNode)->item(0);
		if ($titleNode instanceof DOMElement) {
			$result['inProgressTitle'] = compactNodeText((string) $titleNode->textContent);
		}

		$descriptionNode = $xpath->query('.//*[self::p or self::div or self::span][normalize-space(string(.))!=""][1]', $inProgressNode)->item(0);
		if ($descriptionNode instanceof DOMElement) {
			$result['inProgressDescription'] = trim(substr(compactNodeText((string) $descriptionNode->textContent), 0, 320));
		}

		$result['inProgressSummary'] = trim(substr(compactNodeText((string) $inProgressNode->textContent), 0, 240));
		if ($result['inProgressDescription'] === '' && $result['inProgressSummary'] !== '') {
			$summary = (string) $result['inProgressSummary'];
			$title = (string) $result['inProgressTitle'];
			if ($title !== '' && str_starts_with(strtolower($summary), strtolower($title))) {
				$summary = trim(substr($summary, strlen($title)));
				$summary = ltrim($summary, ":- ");
			}
			$result['inProgressDescription'] = trim(substr($summary, 0, 320));
		}
	}

	$rewardFormNode = $xpath->query('//*[@id="rewardMission" and self::form]')->item(0);
	if ($rewardFormNode instanceof DOMElement) {
		$result['hasRewardMissionForm'] = true;
		$rewardMethod = strtoupper(trim((string) $rewardFormNode->getAttribute('method')));
		if (!in_array($rewardMethod, ['POST', 'GET'], true)) {
			$rewardMethod = 'POST';
		}
		$result['rewardMethod'] = $rewardMethod;
		$rewardAction = trim((string) $rewardFormNode->getAttribute('action'));
		if ($rewardAction !== '') {
			$result['rewardActionUrl'] = resolveUrl($normalizedBaseUrl, $rewardAction);
		}

		$rewardFields = [];
		$rewardInputs = $xpath->query('.//input[@name]', $rewardFormNode);
		if ($rewardInputs instanceof DOMNodeList) {
			foreach ($rewardInputs as $inputNode) {
				if (!($inputNode instanceof DOMElement)) {
					continue;
				}
				$name = trim((string) $inputNode->getAttribute('name'));
				if ($name === '') {
					continue;
				}
				$type = strtolower(trim((string) $inputNode->getAttribute('type')));
				if (in_array($type, ['submit', 'button', 'image'], true)) {
					continue;
				}
				if (in_array($type, ['checkbox', 'radio'], true) && !$inputNode->hasAttribute('checked')) {
					continue;
				}
				$rewardFields[$name] = (string) $inputNode->getAttribute('value');
			}
		}
		$result['rewardFields'] = $rewardFields;
	}

	$skipFormNode = $xpath->query('//*[@id="skipMission" and self::form]')->item(0);
	if (!($skipFormNode instanceof DOMElement)) {
		$skipFormNode = $xpath->query('//form[.//*[self::button or self::input][contains(translate(normalize-space(string(.)), "abcdefghijklmnopqrstuvwxyz", "ABCDEFGHIJKLMNOPQRSTUVWXYZ"), "SKIP") or contains(translate(@value, "abcdefghijklmnopqrstuvwxyz", "ABCDEFGHIJKLMNOPQRSTUVWXYZ"), "SKIP")]][1]')->item(0);
	}

	if ($skipFormNode instanceof DOMElement) {
		$result['hasSkipOption'] = true;
		$skipMethod = strtoupper(trim((string) $skipFormNode->getAttribute('method')));
		if (!in_array($skipMethod, ['POST', 'GET'], true)) {
			$skipMethod = 'POST';
		}
		$result['skipMethod'] = $skipMethod;
		$skipAction = trim((string) $skipFormNode->getAttribute('action'));
		if ($skipAction !== '') {
			$result['skipActionUrl'] = resolveUrl($normalizedBaseUrl, $skipAction);
		}

		$skipFields = [];
		$skipInputs = $xpath->query('.//input[@name]', $skipFormNode);
		if ($skipInputs instanceof DOMNodeList) {
			foreach ($skipInputs as $inputNode) {
				if (!($inputNode instanceof DOMElement)) {
					continue;
				}
				$name = trim((string) $inputNode->getAttribute('name'));
				if ($name === '') {
					continue;
				}
				$type = strtolower(trim((string) $inputNode->getAttribute('type')));
				if (in_array($type, ['submit', 'button', 'image'], true)) {
					continue;
				}
				if (in_array($type, ['checkbox', 'radio'], true) && !$inputNode->hasAttribute('checked')) {
					continue;
				}
				$skipFields[$name] = (string) $inputNode->getAttribute('value');
			}
		}
		$result['skipFields'] = $skipFields;
	}

	if (!$result['hasSkipOption']) {
		$skipNode = $xpath->query('//*[self::a or self::button or self::input][contains(translate(normalize-space(string(.)), "abcdefghijklmnopqrstuvwxyz", "ABCDEFGHIJKLMNOPQRSTUVWXYZ"), "SKIP") or contains(translate(@value, "abcdefghijklmnopqrstuvwxyz", "ABCDEFGHIJKLMNOPQRSTUVWXYZ"), "SKIP")][1]')->item(0);
		if ($skipNode instanceof DOMElement) {
			$result['hasSkipOption'] = true;
			$result['skipMethod'] = 'POST';
			$result['skipActionUrl'] = detectActionUrlFromElement($xpath, $skipNode, $normalizedBaseUrl);
		}
	}

	if ($result['hasSkipOption'] && trim((string) ($result['skipActionUrl'] ?? '')) === '') {
		$result['skipActionUrl'] = resolveUrl($normalizedBaseUrl, 'betaMissions.html');
	}

	if ($result['hasTutorialBallContainer'] || $result['hasMissionDropdown'] || $result['hasInProgressPanel'] || $result['hasRewardMissionForm']) {
		$result['reason'] = 'tutorial-mission-elements-detected';
	}

	return $result;
}

function extractAuctionOffersFromHtml(string $html): array
{
	if (trim($html) === '') {
		return [];
	}

	$dom = new DOMDocument();
	libxml_use_internal_errors(true);
	$dom->loadHTML($html);
	libxml_clear_errors();
	$xpath = new DOMXPath($dom);

	$offers = [];
	$seen = [];
	$bidButtons = $xpath->query('//button[contains(concat(" ", normalize-space(@class), " "), " bid ") and @data-id]');
	if (!$bidButtons) {
		return [];
	}

	foreach ($bidButtons as $buttonNode) {
		if (!($buttonNode instanceof DOMElement)) {
			continue;
		}

		$auctionId = preg_replace('/\D+/', '', trim((string) $buttonNode->getAttribute('data-id')));
		if ($auctionId === '' || isset($seen[$auctionId])) {
			continue;
		}

		$seen[$auctionId] = true;

		$priceInputNode = $xpath->query('//input[@id="bidPrice' . $auctionId . '"][1]')->item(0);
		$bidPrice = $priceInputNode instanceof DOMElement
			? trim((string) $priceInputNode->getAttribute('value'))
			: '';

		$infoButtonNode = $xpath->query('//button[contains(@onclick, "openOfferModal") and @data-id="' . $auctionId . '"][1]')->item(0);
		$itemRaw = '';
		$descriptionRaw = '';
		$seller = '';
		$currentPrice = '';
		$minimalOutbid = '';
		if ($infoButtonNode instanceof DOMElement) {
			$itemRaw = trim((string) $infoButtonNode->getAttribute('data-auction-item'));
			$descriptionRaw = trim((string) $infoButtonNode->getAttribute('data-description'));
			$seller = trim((string) $infoButtonNode->getAttribute('data-seller'));
			$currentPrice = trim((string) $infoButtonNode->getAttribute('data-current-price'));
			$minimalOutbid = trim((string) $infoButtonNode->getAttribute('data-minimal-outbid'));
		}

		$itemSummary = compactNodeText(html_entity_decode(strip_tags($itemRaw), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
		$description = compactNodeText(html_entity_decode(strip_tags($descriptionRaw), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

		$offers[] = [
			'auctionId' => $auctionId,
			'bidPrice' => $bidPrice,
			'minimalOutbid' => $minimalOutbid,
			'currentPrice' => $currentPrice,
			'seller' => $seller,
			'item' => trim(substr($itemSummary, 0, 260)),
			'description' => trim(substr($description, 0, 320)),
		];

		if (count($offers) >= 60) {
			break;
		}
	}

	return $offers;
}

function submitAuctionBid($ch, string $refererUrl, array $defaultHeaders, array $bidData): array
{
	$auctionId = preg_replace('/\D+/', '', trim((string) ($bidData['auctionId'] ?? '')));
	$priceRaw = trim((string) ($bidData['price'] ?? ''));
	$price = preg_replace('/[^0-9.]/', '', str_replace(',', '.', $priceRaw));
	$safeReferer = $refererUrl !== '' ? $refererUrl : serverUrl('auctions.html');

	$result = [
		'attempted' => true,
		'saved' => false,
		'reason' => 'auction-bid-invalid-data',
		'httpStatus' => 0,
		'url' => '',
		'auctionId' => $auctionId,
		'price' => $price,
		'responseSnippet' => '',
		'error' => '',
	];

	if (preg_match('/^\d+$/', $auctionId) !== 1 || preg_match('/^\d+(?:\.\d{1,4})?$/', $price) !== 1) {
		return $result;
	}

	$targetUrls = [
		serverUrl('auctionAction.html?action=BID&id=') . rawurlencode($auctionId) . '&price=' . rawurlencode($price),
		serverUrl('auction.html?id=') . rawurlencode($auctionId) . '&action=BID&price=' . rawurlencode($price),
	];

	$headers = array_merge($defaultHeaders, [
		'Origin: ' . rtrim(serverUrl(''), '/'),
		'Referer: ' . $safeReferer,
		'X-Requested-With: XMLHttpRequest',
	]);

	$lastStep = null;
	$lastTargetUrl = '';
	foreach ($targetUrls as $targetUrl) {
		$step = curlRequest($ch, $targetUrl, [
			CURLOPT_POST => true,
			CURLOPT_HTTPGET => false,
			CURLOPT_POSTFIELDS => '',
			CURLOPT_HTTPHEADER => $headers,
		]);

		$lastStep = $step;
		$lastTargetUrl = $targetUrl;

		$body = (string) ($step['body'] ?? '');
		$bodyLower = strtolower($body);
		$httpOk = (int) ($step['errno'] ?? 0) === 0
			&& (int) ($step['statusCode'] ?? 0) >= 200
			&& (int) ($step['statusCode'] ?? 0) < 400;
		$blocked = preg_match('/\b(error|failed|forbidden|denied|not\s+logged|invalid|already\s+ended|finished)\b/i', $body) === 1;
		$insufficient = preg_match('/\b(insufficient|not\s+enough\s+gold|no\s+money)\b/i', $body) === 1;
		$looksAccepted = str_contains($bodyLower, 'auction') || str_contains($bodyLower, 'bid') || str_contains($bodyLower, 'offers');

		if ($insufficient) {
			return [
				'attempted' => true,
				'saved' => false,
				'reason' => 'auction-bid-insufficient-funds',
				'httpStatus' => (int) ($step['statusCode'] ?? 0),
				'url' => (string) ($step['effectiveUrl'] ?: $targetUrl),
				'auctionId' => $auctionId,
				'price' => $price,
				'responseSnippet' => trim(substr(compactNodeText($body), 0, 280)),
				'error' => (string) ($step['error'] ?? ''),
			];
		}

		if ($httpOk && (!$blocked || $looksAccepted)) {
			return [
				'attempted' => true,
				'saved' => true,
				'reason' => 'auction-bid-submitted',
				'httpStatus' => (int) ($step['statusCode'] ?? 0),
				'url' => (string) ($step['effectiveUrl'] ?: $targetUrl),
				'auctionId' => $auctionId,
				'price' => $price,
				'responseSnippet' => trim(substr(compactNodeText($body), 0, 280)),
				'error' => (string) ($step['error'] ?? ''),
			];
		}
	}

	$fallbackStatus = (int) (($lastStep['statusCode'] ?? 0));
	$fallbackErrno = (int) (($lastStep['errno'] ?? 0));
	$fallbackBody = (string) (($lastStep['body'] ?? ''));

	return [
		'attempted' => true,
		'saved' => false,
		'reason' => $fallbackErrno !== 0 ? 'auction-bid-request-error' : 'auction-bid-rejected',
		'httpStatus' => $fallbackStatus,
		'url' => (string) (($lastStep['effectiveUrl'] ?? '') !== '' ? $lastStep['effectiveUrl'] : $lastTargetUrl),
		'auctionId' => $auctionId,
		'price' => $price,
		'responseSnippet' => trim(substr(compactNodeText($fallbackBody), 0, 280)),
		'error' => (string) (($lastStep['error'] ?? '')),
	];
}

function normalizeArticlePageUrl(string $rawUrl, string $fallbackBaseUrl = ''): string
{
	if (trim($fallbackBaseUrl) === '') {
		$fallbackBaseUrl = serverUrl('index.html');
	}

	$raw = trim(html_entity_decode($rawUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
	if ($raw === '') {
		return '';
	}

	$host = (string) parse_url(serverUrl('index.html'), PHP_URL_HOST);
	if ($host === '') {
		$host = 'vara.e-sim.org';
	}
	if (preg_match('/^https?:\/\/([^\/]+)/i', $raw, $hostMatch) === 1) {
		$candidateHost = strtolower(trim((string) ($hostMatch[1] ?? '')));
		if (preg_match('/^[a-z0-9.-]+\.e-sim\.org$/', $candidateHost) === 1) {
			$host = $candidateHost;
		}
	}

	if (preg_match('/^\d+$/', $raw) === 1) {
		return 'https://' . $host . '/article.html?id=' . $raw;
	}

	$resolved = preg_match('/^https?:\/\//i', $raw) === 1
		? $raw
		: resolveUrl($fallbackBaseUrl, $raw);

	if (preg_match('/^https?:\/\/([^\/]+)/i', $resolved, $resolvedHostMatch) === 1) {
		$candidateHost = strtolower(trim((string) ($resolvedHostMatch[1] ?? '')));
		if (preg_match('/^[a-z0-9.-]+\.e-sim\.org$/', $candidateHost) === 1) {
			$host = $candidateHost;
		}
	}

	$id = '';
	if (preg_match('/[?&]id=(\d+)/i', $resolved, $idMatch) === 1) {
		$id = (string) ($idMatch[1] ?? '');
	}

	if ($id === '') {
		return '';
	}

	return 'https://' . $host . '/article.html?id=' . $id;
}

function extractArticleActionsFromHtml(string $html, string $baseUrl): array
{
	$result = [
		'articleId' => '',
		'articleTitle' => '',
		'voteDetected' => false,
		'subscribeDetected' => false,
		'voteActionUrl' => '',
		'subscribeActionUrl' => '',
	];

	if (trim($html) === '') {
		return $result;
	}

	if (preg_match('/[?&]id=(\d+)/i', $baseUrl, $idMatch) === 1) {
		$result['articleId'] = (string) ($idMatch[1] ?? '');
	}

	$dom = new DOMDocument();
	libxml_use_internal_errors(true);
	$dom->loadHTML($html);
	libxml_clear_errors();
	$xpath = new DOMXPath($dom);

	$titleNode = $xpath->query('//h1[1]')->item(0);
	if (!($titleNode instanceof DOMElement)) {
		$titleNode = $xpath->query('//h2[1]')->item(0);
	}
	if ($titleNode instanceof DOMElement) {
		$result['articleTitle'] = compactNodeText((string) $titleNode->textContent);
	}

	$voteNode = $xpath->query('//*[@id="voteButton"][1]')->item(0);
	if ($voteNode instanceof DOMElement) {
		$result['voteDetected'] = true;
	}
	if (!$result['voteDetected']) {
		$voteHintNode = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " subVoteButton ") and contains(normalize-space(string(.)), "+1")][1]')->item(0);
		if ($voteHintNode instanceof DOMElement) {
			$result['voteDetected'] = true;
		}
	}

	$subscribeNode = $xpath->query('//*[@id="subLink"][1]')->item(0);
	if ($subscribeNode instanceof DOMElement) {
		$result['subscribeDetected'] = true;
	}
	if (!$result['subscribeDetected']) {
		$subscribeHintNode = $xpath->query('//*[@id="subButton"][1]')->item(0);
		if ($subscribeHintNode instanceof DOMElement) {
			$result['subscribeDetected'] = true;
		}
	}

	$articleUrl = normalizeArticlePageUrl($baseUrl, $baseUrl);
	if ($articleUrl !== '') {
		if (!empty($result['voteDetected'])) {
			$result['voteActionUrl'] = $articleUrl . '&vote=1';
		}
		if (!empty($result['subscribeDetected'])) {
			$result['subscribeActionUrl'] = $articleUrl . '&subscribe=true';
		}
	}

	return $result;
}

function submitArticleVote($ch, string $articleUrl, array $defaultHeaders, array $voteData): array
{
	$articleId = preg_replace('/\D+/', '', trim((string) ($voteData['articleId'] ?? '')));
	$voteActionUrl = trim((string) ($voteData['voteActionUrl'] ?? ''));
	$safeArticleUrl = $articleUrl !== '' ? $articleUrl : normalizeArticlePageUrl($articleId);

	$result = [
		'attempted' => true,
		'saved' => false,
		'reason' => 'article-vote-invalid-data',
		'httpStatus' => 0,
		'url' => '',
		'articleId' => $articleId,
		'responseSnippet' => '',
		'error' => '',
	];

	if ($articleId === '' || $safeArticleUrl === '') {
		return $result;
	}

	$targetUrls = [];
	if ($voteActionUrl !== '') {
		$targetUrls[] = $voteActionUrl;
	}
	$targetUrls[] = $safeArticleUrl . '&vote=1';
	$targetUrls[] = serverUrl('articleVote.html?id=') . rawurlencode($articleId);
	$targetUrls[] = serverUrl('articleAction.html?action=VOTE&id=') . rawurlencode($articleId);

	$headers = array_merge($defaultHeaders, [
		'Origin: ' . rtrim(serverUrl(''), '/'),
		'Referer: ' . $safeArticleUrl,
		'X-Requested-With: XMLHttpRequest',
	]);

	$lastStep = null;
	$lastTargetUrl = '';
	foreach ($targetUrls as $targetUrl) {
		$step = curlRequest($ch, $targetUrl, [
			CURLOPT_POST => false,
			CURLOPT_HTTPGET => true,
			CURLOPT_HTTPHEADER => $headers,
		]);
		$lastStep = $step;
		$lastTargetUrl = $targetUrl;

		$body = (string) ($step['body'] ?? '');
		$httpOk = (int) ($step['errno'] ?? 0) === 0
			&& (int) ($step['statusCode'] ?? 0) >= 200
			&& (int) ($step['statusCode'] ?? 0) < 400;
		$looksError = preg_match('/\b(error|failed|forbidden|denied|not\s+logged|captcha|already\s+voted)\b/i', $body) === 1;
		$looksVote = str_contains(strtolower($body), 'votebutton') || str_contains(strtolower($body), '+1') || str_contains(strtolower($body), 'article');

		if ($httpOk && (!$looksError || $looksVote)) {
			return [
				'attempted' => true,
				'saved' => true,
				'reason' => 'article-vote-submitted',
				'httpStatus' => (int) ($step['statusCode'] ?? 0),
				'url' => (string) ($step['effectiveUrl'] ?: $targetUrl),
				'articleId' => $articleId,
				'responseSnippet' => trim(substr(compactNodeText($body), 0, 280)),
				'error' => (string) ($step['error'] ?? ''),
			];
		}
	}

	return [
		'attempted' => true,
		'saved' => false,
		'reason' => (int) (($lastStep['errno'] ?? 0)) !== 0 ? 'article-vote-request-error' : 'article-vote-rejected',
		'httpStatus' => (int) (($lastStep['statusCode'] ?? 0)),
		'url' => (string) (($lastStep['effectiveUrl'] ?? '') !== '' ? $lastStep['effectiveUrl'] : $lastTargetUrl),
		'articleId' => $articleId,
		'responseSnippet' => trim(substr(compactNodeText((string) (($lastStep['body'] ?? ''))), 0, 280)),
		'error' => (string) (($lastStep['error'] ?? '')),
	];
}

function submitArticleSubscribe($ch, string $articleUrl, array $defaultHeaders, array $subscribeData): array
{
	$articleId = preg_replace('/\D+/', '', trim((string) ($subscribeData['articleId'] ?? '')));
	$subscribeActionUrl = trim((string) ($subscribeData['subscribeActionUrl'] ?? ''));
	$safeArticleUrl = $articleUrl !== '' ? $articleUrl : normalizeArticlePageUrl($articleId);

	$result = [
		'attempted' => true,
		'saved' => false,
		'reason' => 'article-subscribe-invalid-data',
		'httpStatus' => 0,
		'url' => '',
		'articleId' => $articleId,
		'responseSnippet' => '',
		'error' => '',
	];

	if ($articleId === '' || $safeArticleUrl === '') {
		return $result;
	}

	$targetUrls = [];
	if ($subscribeActionUrl !== '') {
		$targetUrls[] = $subscribeActionUrl;
	}
	$targetUrls[] = $safeArticleUrl . '&subscribe=true';
	$targetUrls[] = $safeArticleUrl . '&sub=1';
	$targetUrls[] = serverUrl('articleAction.html?action=SUBSCRIBE&id=') . rawurlencode($articleId);

	$headers = array_merge($defaultHeaders, [
		'Origin: ' . rtrim(serverUrl(''), '/'),
		'Referer: ' . $safeArticleUrl,
		'X-Requested-With: XMLHttpRequest',
	]);

	$lastStep = null;
	$lastTargetUrl = '';
	foreach ($targetUrls as $targetUrl) {
		$step = curlRequest($ch, $targetUrl, [
			CURLOPT_POST => false,
			CURLOPT_HTTPGET => true,
			CURLOPT_HTTPHEADER => $headers,
		]);
		$lastStep = $step;
		$lastTargetUrl = $targetUrl;

		$body = (string) ($step['body'] ?? '');
		$httpOk = (int) ($step['errno'] ?? 0) === 0
			&& (int) ($step['statusCode'] ?? 0) >= 200
			&& (int) ($step['statusCode'] ?? 0) < 400;
		$looksError = preg_match('/\b(error|failed|forbidden|denied|not\s+logged|captcha)\b/i', $body) === 1;
		$looksSubscribe = str_contains(strtolower($body), 'subbutton') || str_contains(strtolower($body), 'article') || str_contains(strtolower($body), 'subscribe');

		if ($httpOk && (!$looksError || $looksSubscribe)) {
			return [
				'attempted' => true,
				'saved' => true,
				'reason' => 'article-subscribe-submitted',
				'httpStatus' => (int) ($step['statusCode'] ?? 0),
				'url' => (string) ($step['effectiveUrl'] ?: $targetUrl),
				'articleId' => $articleId,
				'responseSnippet' => trim(substr(compactNodeText($body), 0, 280)),
				'error' => (string) ($step['error'] ?? ''),
			];
		}
	}

	return [
		'attempted' => true,
		'saved' => false,
		'reason' => (int) (($lastStep['errno'] ?? 0)) !== 0 ? 'article-subscribe-request-error' : 'article-subscribe-rejected',
		'httpStatus' => (int) (($lastStep['statusCode'] ?? 0)),
		'url' => (string) (($lastStep['effectiveUrl'] ?? '') !== '' ? $lastStep['effectiveUrl'] : $lastTargetUrl),
		'articleId' => $articleId,
		'responseSnippet' => trim(substr(compactNodeText((string) (($lastStep['body'] ?? ''))), 0, 280)),
		'error' => (string) (($lastStep['error'] ?? '')),
	];
}

function normalizeElectionsPageUrl(string $rawUrl, string $fallbackBaseUrl = ''): string
{
	if (trim($fallbackBaseUrl) === '') {
		$fallbackBaseUrl = serverUrl('index.html');
	}

	$raw = trim(html_entity_decode($rawUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
	if ($raw === '') {
		return serverUrl('elections.html?electionType=CONGRESS');
	}

	$resolved = preg_match('/^https?:\/\//i', $raw) === 1
		? $raw
		: resolveUrl($fallbackBaseUrl, $raw);

	if (!str_contains(strtolower($resolved), '/elections.html')) {
		return '';
	}

	if (!str_contains(strtolower($resolved), 'electiontype=')) {
		$resolved .= (str_contains($resolved, '?') ? '&' : '?') . 'electionType=CONGRESS';
	}

	return $resolved;
}

function normalizeMilitaryUnitPageUrl(string $rawUrl, string $fallbackBaseUrl = ''): string
{
	if (trim($fallbackBaseUrl) === '') {
		$fallbackBaseUrl = serverUrl('index.html');
	}

	$raw = trim(html_entity_decode($rawUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
	if ($raw === '') {
		return '';
	}

	if (preg_match('/^\d+$/', $raw) === 1) {
		return serverUrl('militaryUnit.html?id=') . $raw;
	}

	$resolved = preg_match('/^https?:\/\//i', $raw) === 1
		? $raw
		: resolveUrl($fallbackBaseUrl, $raw);

	if (!str_contains(strtolower($resolved), '/militaryunit.html')) {
		return '';
	}

	return $resolved;
}

function extractPageActionOptionsFromHtml(string $html, string $baseUrl): array
{
	if (trim($html) === '') {
		return [];
	}

	$dom = new DOMDocument();
	libxml_use_internal_errors(true);
	$dom->loadHTML($html);
	libxml_clear_errors();
	$xpath = new DOMXPath($dom);

	$options = [];
	$seen = [];

	$formNodes = $xpath->query('//form');
	if ($formNodes instanceof DOMNodeList) {
		foreach ($formNodes as $formNode) {
			if (!($formNode instanceof DOMElement)) {
				continue;
			}

			$action = trim((string) $formNode->getAttribute('action'));
			$method = strtoupper(trim((string) $formNode->getAttribute('method')));
			if ($method === '') {
				$method = 'POST';
			}
			$resolvedAction = $action !== '' ? resolveUrl($baseUrl, $action) : '';
			$id = trim((string) $formNode->getAttribute('id'));
			$name = trim((string) $formNode->getAttribute('name'));

			$fieldNames = [];
			$inputNodes = $xpath->query('.//input[@name] | .//select[@name] | .//textarea[@name]', $formNode);
			if ($inputNodes instanceof DOMNodeList) {
				foreach ($inputNodes as $inputNode) {
					if (!($inputNode instanceof DOMElement)) {
						continue;
					}
					$fieldName = trim((string) $inputNode->getAttribute('name'));
					if ($fieldName !== '') {
						$fieldNames[] = $fieldName;
					}
				}
			}

			$key = 'form|' . $resolvedAction . '|' . $method . '|' . implode(',', $fieldNames);
			if (isset($seen[$key])) {
				continue;
			}
			$seen[$key] = true;

			$options[] = [
				'type' => 'form',
				'label' => trim($id !== '' ? ('#' . $id) : $name),
				'action' => $resolvedAction,
				'method' => $method,
				'fields' => array_values(array_unique($fieldNames)),
			];
			if (count($options) >= 40) {
				break;
			}
		}
	}

	if (count($options) < 40) {
		$linkNodes = $xpath->query('//a[@href]');
		if ($linkNodes instanceof DOMNodeList) {
			foreach ($linkNodes as $linkNode) {
				if (!($linkNode instanceof DOMElement)) {
					continue;
				}
				$href = trim((string) $linkNode->getAttribute('href'));
				if ($href === '' || $href === '#') {
					continue;
				}
				$resolvedHref = resolveUrl($baseUrl, $href);
				$linkText = trim(substr(compactNodeText((string) $linkNode->textContent), 0, 120));
				$key = 'link|' . $resolvedHref . '|' . $linkText;
				if (isset($seen[$key])) {
					continue;
				}
				$seen[$key] = true;
				$options[] = [
					'type' => 'link',
					'label' => $linkText,
					'action' => $resolvedHref,
					'method' => 'GET',
					'fields' => [],
				];
				if (count($options) >= 40) {
					break;
				}
			}
		}
	}

	return $options;
}

function extractPagePrimaryTitle(string $html): string
{
	if (trim($html) === '') {
		return '';
	}

	$dom = new DOMDocument();
	libxml_use_internal_errors(true);
	$dom->loadHTML($html);
	libxml_clear_errors();
	$xpath = new DOMXPath($dom);

	$titleNode = $xpath->query('//h1[normalize-space(string(.))!=""][1]')->item(0);
	if (!($titleNode instanceof DOMElement)) {
		$titleNode = $xpath->query('//h2[normalize-space(string(.))!=""][1]')->item(0);
	}

	return $titleNode instanceof DOMElement ? compactNodeText((string) $titleNode->textContent) : '';
}

function extractElectionsCongressCandidateActionUrl(string $html, string $baseUrl): string
{
	if (trim($html) === '') {
		return '';
	}

	if (preg_match('/Elections\.candidate\([^\)]*["\']([^"\']*congressElectionsCandidate[^"\']*)["\']/i', $html, $match) === 1) {
		$raw = trim((string) ($match[1] ?? ''));
		if ($raw !== '') {
			return resolveUrl($baseUrl, $raw);
		}
	}

	if (stripos($html, 'congressElectionsCandidate') !== false) {
		return resolveUrl($baseUrl, 'congressElectionsCandidate');
	}

	return '';
}

function extractMilitaryUnitApplyFormFromHtml(string $html, string $baseUrl): array
{
	$result = [
		'applyDetected' => false,
		'applyActionUrl' => '',
		'applyMethod' => 'POST',
		'applyFields' => [],
		'applyDefaultMessage' => '',
	];

	if (trim($html) === '') {
		return $result;
	}

	$dom = new DOMDocument();
	libxml_use_internal_errors(true);
	$dom->loadHTML($html);
	libxml_clear_errors();
	$xpath = new DOMXPath($dom);

	$formNode = $xpath->query('//form[contains(translate(@action, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "militaryunitsactions") and .//input[@name="action" and translate(@value, "abcdefghijklmnopqrstuvwxyz", "ABCDEFGHIJKLMNOPQRSTUVWXYZ")="SEND_APPLICATION"]]')->item(0);
	if (!($formNode instanceof DOMElement)) {
		return $result;
	}

	$actionRaw = trim((string) $formNode->getAttribute('action'));
	$method = strtoupper(trim((string) $formNode->getAttribute('method')));
	if ($method === '') {
		$method = 'POST';
	}

	$fields = [];
	$fieldNodes = $xpath->query('.//input | .//select | .//textarea', $formNode);
	if ($fieldNodes instanceof DOMNodeList) {
		foreach ($fieldNodes as $fieldNode) {
			if (!($fieldNode instanceof DOMElement)) {
				continue;
			}
			$fieldName = trim((string) $fieldNode->getAttribute('name'));
			if ($fieldName === '') {
				continue;
			}

			$tagName = strtolower($fieldNode->tagName);
			$type = $tagName === 'input' ? strtolower(trim((string) $fieldNode->getAttribute('type'))) : $tagName;
			if ($tagName === 'input' && in_array($type, ['button', 'submit', 'image', 'file'], true)) {
				continue;
			}

			if ($tagName === 'textarea') {
				$fields[$fieldName] = trim((string) $fieldNode->textContent);
				continue;
			}

			$fields[$fieldName] = trim((string) $fieldNode->getAttribute('value'));
		}
	}

	$result['applyDetected'] = true;
	$result['applyActionUrl'] = resolveUrl($baseUrl, $actionRaw !== '' ? $actionRaw : 'militaryUnitsActions.html');
	$result['applyMethod'] = $method;
	$result['applyFields'] = $fields;
	$result['applyDefaultMessage'] = trim((string) ($fields['message'] ?? ''));

	return $result;
}

function extractLoginForm(string $html, string $baseUrl): array
{
	$dom = new DOMDocument();
	libxml_use_internal_errors(true);
	$dom->loadHTML($html);
	libxml_clear_errors();

	$xpath = new DOMXPath($dom);
	$forms = $xpath->query('//form[.//input[@name="login"] and .//input[@name="password"]]');
	if (!$forms || $forms->length === 0) {
		return [
			'found' => false,
			'actionUrl' => '',
			'method' => 'POST',
			'fields' => [],
		];
	}

	$selectedForm = null;
	foreach ($forms as $candidateNode) {
		if (!($candidateNode instanceof DOMElement)) {
			continue;
		}

		$candidateAction = strtolower(trim((string) $candidateNode->getAttribute('action')));
		$candidateId = strtolower(trim((string) $candidateNode->getAttribute('id')));
		$isRegistration = str_contains($candidateAction, 'registration') || str_contains($candidateId, 'register');
		if ($isRegistration) {
			continue;
		}

		if (str_contains($candidateAction, 'iogin') || str_contains($candidateAction, 'login')) {
			$selectedForm = $candidateNode;
			break;
		}

		if ($selectedForm === null) {
			$selectedForm = $candidateNode;
		}
	}

	if (!($selectedForm instanceof DOMElement)) {
		return [
			'found' => false,
			'actionUrl' => '',
			'method' => 'POST',
			'fields' => [],
		];
	}

	$form = $selectedForm;
	$actionRaw = trim((string) $form->getAttribute('action'));
	$method = strtoupper(trim((string) $form->getAttribute('method')));
	if ($method === '') {
		$method = 'POST';
	}

	$fields = [];
	$inputs = $xpath->query('.//input', $form);
	if ($inputs) {
		foreach ($inputs as $input) {
			if (!($input instanceof DOMElement)) {
				continue;
			}

			$name = trim((string) $input->getAttribute('name'));
			if ($name === '') {
				continue;
			}

			$type = strtolower(trim((string) $input->getAttribute('type')));
			if ($type === 'submit' || $type === 'button' || $type === 'image') {
				continue;
			}

			$fields[$name] = (string) $input->getAttribute('value');
		}
	}

	return [
		'found' => true,
		'actionUrl' => resolveUrl($baseUrl, $actionRaw),
		'method' => $method,
		'fields' => $fields,
	];
}

function extractAdvancedRegistrationForm(string $html, string $baseUrl): array
{
	$dom = new DOMDocument();
	libxml_use_internal_errors(true);
	$dom->loadHTML($html);
	libxml_clear_errors();

	$xpath = new DOMXPath($dom);
	$forms = $xpath->query('//form[.//input[@name="login"] and .//input[@name="password"]]');
	if (!$forms || $forms->length === 0) {
		return [
			'found' => false,
			'actionUrl' => '',
			'method' => 'POST',
			'fields' => [],
			'preferredCountryField' => '',
			'preferredCountryValue' => '',
		];
	}

	$selectedForm = null;
	foreach ($forms as $candidateNode) {
		if (!($candidateNode instanceof DOMElement)) {
			continue;
		}

		$candidateAction = strtolower(trim((string) $candidateNode->getAttribute('action')));
		$candidateId = strtolower(trim((string) $candidateNode->getAttribute('id')));
		$countrySelectNodes = $xpath->query('.//select[contains(translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "country")]', $candidateNode);
		$hasCountrySelect = $countrySelectNodes instanceof DOMNodeList && $countrySelectNodes->length > 0;
		if (str_contains($candidateAction, 'registration') || str_contains($candidateId, 'register') || $hasCountrySelect) {
			$selectedForm = $candidateNode;
			break;
		}
	}

	if (!($selectedForm instanceof DOMElement)) {
		return [
			'found' => false,
			'actionUrl' => '',
			'method' => 'POST',
			'fields' => [],
			'preferredCountryField' => '',
			'preferredCountryValue' => '',
		];
	}

	$form = $selectedForm;
	$actionRaw = trim((string) $form->getAttribute('action'));
	$method = strtoupper(trim((string) $form->getAttribute('method')));
	if ($method === '') {
		$method = 'POST';
	}

	$fields = [];
	$inputs = $xpath->query('.//input', $form);
	if ($inputs) {
		foreach ($inputs as $input) {
			if (!($input instanceof DOMElement)) {
				continue;
			}

			$name = trim((string) $input->getAttribute('name'));
			if ($name === '') {
				continue;
			}

			$type = strtolower(trim((string) $input->getAttribute('type')));
			if ($type === 'submit' || $type === 'button' || $type === 'image') {
				continue;
			}

			$fields[$name] = (string) $input->getAttribute('value');
		}
	}

	$preferredCountryField = '';
	$preferredCountryValue = '';
	$selects = $xpath->query('.//select', $form);
	if ($selects) {
		foreach ($selects as $select) {
			if (!($select instanceof DOMElement)) {
				continue;
			}

			$name = trim((string) $select->getAttribute('name'));
			if ($name === '') {
				continue;
			}

			$options = $xpath->query('.//option', $select);
			$defaultValue = '';
			$usValue = '';
			if ($options) {
				foreach ($options as $idx => $option) {
					if (!($option instanceof DOMElement)) {
						continue;
					}

					$value = trim((string) $option->getAttribute('value'));
					$text = normalizeSidebarLabel((string) $option->textContent);
					if ($idx === 0) {
						$defaultValue = $value;
					}
					if ($value === '26' || str_contains($text, 'usa') || str_contains($text, 'united states') || str_contains($text, 'estados unidos')) {
						$usValue = $value;
					}
				}
			}

			$fields[$name] = $defaultValue;
			if ($usValue !== '' && str_contains(normalizeSidebarLabel($name), 'country')) {
				$preferredCountryField = $name;
				$preferredCountryValue = $usValue;
			}
		}
	}

	return [
		'found' => true,
		'actionUrl' => resolveUrl($baseUrl, $actionRaw),
		'method' => $method,
		'fields' => $fields,
		'preferredCountryField' => $preferredCountryField,
		'preferredCountryValue' => $preferredCountryValue,
	];
}

function buildAdvancedRegistrationPayload(array $registrationForm, string $username, string $password, string $country): array
{
	$fields = is_array($registrationForm['fields'] ?? null) ? $registrationForm['fields'] : [];

	foreach (array_keys($fields) as $name) {
		$key = normalizeSidebarLabel((string) $name);
		if ($key === '') {
			continue;
		}

		if (str_contains($key, 'login') || str_contains($key, 'user') || str_contains($key, 'nick')) {
			$fields[$name] = $username;
			continue;
		}

		if (str_contains($key, 'pass')) {
			$fields[$name] = $password;
			continue;
		}

		if ((str_contains($key, 'mail') || str_contains($key, 'email')) && trim((string) $fields[$name]) === '') {
			$safeUser = preg_replace('/[^a-z0-9]/i', '', strtolower($username));
			$fields[$name] = ($safeUser !== '' ? $safeUser : 'esimuser') . '@example.com';
		}
	}

	$preferredCountryField = trim((string) ($registrationForm['preferredCountryField'] ?? ''));
	$preferredCountryValue = trim((string) ($registrationForm['preferredCountryValue'] ?? ''));
	if ($preferredCountryField !== '' && $preferredCountryValue !== '') {
		$fields[$preferredCountryField] = $preferredCountryValue;
	} else {
		foreach (array_keys($fields) as $name) {
			if (str_contains(normalizeSidebarLabel((string) $name), 'country')) {
				$fields[$name] = $country;
			}
		}
	}

	return $fields;
}

function hasTaskTrainButton(string $html): bool
{
	$source = strtolower($html);
	return str_contains($source, 'id="taskbuttontrain"') || str_contains($source, "id='taskbuttontrain'");
}

function hasTaskWorkButton(string $html): bool
{
	$source = strtolower($html);
	return str_contains($source, 'id="taskbuttonwork"') || str_contains($source, "id='taskbuttonwork'");
}

function submitTrainTask($ch, string $refererUrl, array $defaultHeaders): array
{
	$fallbackRef = serverUrl('index.html');
	$safeReferer = $refererUrl !== '' ? $refererUrl : $fallbackRef;
	$actionUrl = resolveUrl($safeReferer, 'traIn/ajax');
	$postHeaders = [
		'Content-Type: application/x-www-form-urlencoded',
		'Origin: ' . rtrim(serverUrl(''), '/'),
		'Referer: ' . $safeReferer,
	];

	return curlRequest($ch, $actionUrl, [
		CURLOPT_POST => true,
		CURLOPT_POSTFIELDS => '',
		CURLOPT_HTTPHEADER => array_merge($defaultHeaders, $postHeaders),
	]);
}

function extractWorkAjaxQueryParams(string $html): string
{
	$params = ['action=work'];

	if (preg_match('/rollbackDay\s*=\s*["\']([^"\']+)["\']/i', $html, $match)) {
		$rollbackDay = trim((string) ($match[1] ?? ''));
		if ($rollbackDay !== '') {
			$params[] = 'rollbackDay=' . rawurlencode($rollbackDay);
		}
	}

	if (preg_match('/secondDayWorkParamForFirebase\s*=\s*["\']([^"\']*)["\']/i', $html, $match)) {
		$raw = trim((string) ($match[1] ?? ''));
		$raw = ltrim($raw, '?&');
		if ($raw !== '') {
			$params[] = $raw;
		}
	}

	if (preg_match('/thirdDayWorkParamForFirebase\s*=\s*["\']([^"\']*)["\']/i', $html, $match)) {
		$raw = trim((string) ($match[1] ?? ''));
		$raw = ltrim($raw, '?&');
		if ($raw !== '') {
			$params[] = $raw;
		}
	}

	return implode('&', $params);
}

function submitWorkTask($ch, string $refererUrl, array $defaultHeaders, string $html): array
{
	$fallbackRef = serverUrl('index.html');
	$safeReferer = $refererUrl !== '' ? $refererUrl : $fallbackRef;
	$query = extractWorkAjaxQueryParams($html);
	$actionUrl = resolveUrl($safeReferer, 'work/ajax?' . $query);
	$postHeaders = [
		'Content-Type: application/x-www-form-urlencoded',
		'Origin: ' . rtrim(serverUrl(''), '/'),
		'Referer: ' . $safeReferer,
	];

	return curlRequest($ch, $actionUrl, [
		CURLOPT_POST => true,
		CURLOPT_POSTFIELDS => '',
		CURLOPT_HTTPHEADER => array_merge($defaultHeaders, $postHeaders),
	]);
}

function extractWorkplaceInfo(string $html, string $baseUrl): array
{
	$result = [
		'companyName' => '',
		'companyUrl' => '',
		'companyOwner' => '',
		'companyOwnerType' => '',
		'companyOwnerUrl' => '',
		'canWork' => false,
		'canLeave' => false,
		'leaveActionUrl' => '',
		'leaveFields' => [],
	];

	if (trim($html) === '') {
		return $result;
	}

	$dom = new DOMDocument();
	libxml_use_internal_errors(true);
	$dom->loadHTML($html);
	libxml_clear_errors();
	$xpath = new DOMXPath($dom);

	$companyNode = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " statsGrid ")]//div[contains(concat(" ", normalize-space(@class), " "), " companyStats ")]//a[contains(@href,"company.html?id=")][1]')->item(0);
	if (!($companyNode instanceof DOMElement)) {
		$companyNode = $xpath->query('//a[contains(@href,"company.html?id=") and contains(concat(" ", normalize-space(@class), " "), " companyName ")][1]')->item(0);
	}

	if ($companyNode instanceof DOMElement) {
		$companyHref = trim((string) $companyNode->getAttribute('href'));
		$result['companyUrl'] = resolveUrl($baseUrl, $companyHref);
		$result['companyName'] = compactNodeText((string) $companyNode->textContent);
	}

	$ownerNode = $xpath->query('//*[@id="companyOwner"][1]')->item(0);
	if ($ownerNode instanceof DOMElement) {
		$ownerAnchor = $xpath->query('.//a[1]', $ownerNode)->item(0);
		if ($ownerAnchor instanceof DOMElement) {
			$ownerHref = trim((string) $ownerAnchor->getAttribute('href'));
			$ownerName = compactNodeText((string) $ownerAnchor->textContent);

			$result['companyOwner'] = $ownerName;
			$result['companyOwnerUrl'] = $ownerHref !== '' ? resolveUrl($baseUrl, $ownerHref) : '';

			$ownerHrefLower = strtolower($ownerHref);
			if (str_contains($ownerHrefLower, 'militaryunit.html')) {
				$result['companyOwnerType'] = 'military_unit';
			} elseif (str_contains($ownerHrefLower, 'profile.html')) {
				$result['companyOwnerType'] = 'user';
			} else {
				$result['companyOwnerType'] = 'unknown';
			}
		}

		if ($result['companyOwner'] === '') {
			$result['companyOwner'] = compactNodeText((string) $ownerNode->textContent);
		}
	}

	$result['canWork'] = hasTaskWorkButton($html) || str_contains(strtolower($html), 'work/ajax?action=work');

	$leaveForm = $xpath->query('//form[@id="leaveWorkForm" and .//input[@name="action" and @value="leave"]]')->item(0);
	if ($leaveForm instanceof DOMElement) {
		$result['canLeave'] = true;
		$leaveActionRaw = trim((string) $leaveForm->getAttribute('action'));
		$result['leaveActionUrl'] = resolveUrl($baseUrl, $leaveActionRaw !== '' ? $leaveActionRaw : 'work2.html');
		$inputs = $xpath->query('.//input[@name]', $leaveForm);
		$fields = [];
		if ($inputs) {
			foreach ($inputs as $inputNode) {
				if (!($inputNode instanceof DOMElement)) {
					continue;
				}

				$type = strtolower(trim((string) $inputNode->getAttribute('type')));
				if ($type === 'submit' || $type === 'button' || $type === 'image') {
					continue;
				}

				$name = trim((string) $inputNode->getAttribute('name'));
				if ($name === '') {
					continue;
				}
				$fields[$name] = (string) $inputNode->getAttribute('value');
			}
		}
		$result['leaveFields'] = $fields;
	}

	return $result;
}

function submitLeaveJob($ch, string $refererUrl, array $defaultHeaders, string $actionUrl, array $fields): array
{
	$fallbackRef = serverUrl('work2.html');
	$safeReferer = $refererUrl !== '' ? $refererUrl : $fallbackRef;
	$postHeaders = [
		'Content-Type: application/x-www-form-urlencoded',
		'Origin: ' . rtrim(serverUrl(''), '/'),
		'Referer: ' . $safeReferer,
	];

	return curlRequest($ch, $actionUrl, [
		CURLOPT_POST => true,
		CURLOPT_POSTFIELDS => http_build_query($fields),
		CURLOPT_HTTPHEADER => array_merge($defaultHeaders, $postHeaders),
	]);
}

function normalizeCompanyPageUrl(string $rawUrl, string $fallbackBaseUrl = ''): string
{
	if (trim($fallbackBaseUrl) === '') {
		$fallbackBaseUrl = serverUrl('index.html');
	}

	$raw = trim(html_entity_decode($rawUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
	if ($raw === '') {
		return '';
	}

	$host = (string) parse_url(serverUrl('index.html'), PHP_URL_HOST);
	if ($host === '') {
		$host = 'vara.e-sim.org';
	}
	if (preg_match('/^https?:\/\/([^\/]+)$/i', preg_replace('/\/.*/', '', $raw), $hostMatch) === 1) {
		$candidateHost = strtolower(trim((string) ($hostMatch[1] ?? '')));
		if (preg_match('/^[a-z0-9.-]+\.e-sim\.org$/', $candidateHost) === 1) {
			$host = $candidateHost;
		}
	}

	if (preg_match('/^\d+$/', $raw) === 1) {
		return 'https://' . $host . '/company.html?id=' . $raw;
	}

	$resolved = preg_match('/^https?:\/\//i', $raw) === 1
		? $raw
		: resolveUrl($fallbackBaseUrl, $raw);

	if (preg_match('/^https?:\/\/([^\/]+)/i', $resolved, $hostMatch) === 1) {
		$candidateHost = strtolower(trim((string) ($hostMatch[1] ?? '')));
		if (preg_match('/^[a-z0-9.-]+\.e-sim\.org$/', $candidateHost) === 1) {
			$host = $candidateHost;
		}
	}

	$id = '';
	if (preg_match('/[?&]id=(\d+)/i', $resolved, $idMatch) === 1) {
		$id = (string) ($idMatch[1] ?? '');
	}

	if ($id === '') {
		return '';
	}

	return 'https://' . $host . '/company.html?id=' . $id;
}

function normalizePartyPageUrl(string $rawUrl, string $fallbackBaseUrl = ''): string
{
	if (trim($fallbackBaseUrl) === '') {
		$fallbackBaseUrl = serverUrl('index.html');
	}

	$raw = trim(html_entity_decode($rawUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
	if ($raw === '') {
		return '';
	}

	$host = (string) parse_url(serverUrl('index.html'), PHP_URL_HOST);
	if ($host === '') {
		$host = 'vara.e-sim.org';
	}
	if (preg_match('/^https?:\/\/([^\/]+)/i', $raw, $hostMatch) === 1) {
		$candidateHost = strtolower(trim((string) ($hostMatch[1] ?? '')));
		if (preg_match('/^[a-z0-9.-]+\.e-sim\.org$/', $candidateHost) === 1) {
			$host = $candidateHost;
		}
	}

	if (preg_match('/^\d+$/', $raw) === 1) {
		return 'https://' . $host . '/party.html?id=' . $raw;
	}

	$resolved = preg_match('/^https?:\/\//i', $raw) === 1
		? $raw
		: resolveUrl($fallbackBaseUrl, $raw);

	if (preg_match('/^https?:\/\/([^\/]+)/i', $resolved, $resolvedHostMatch) === 1) {
		$candidateHost = strtolower(trim((string) ($resolvedHostMatch[1] ?? '')));
		if (preg_match('/^[a-z0-9.-]+\.e-sim\.org$/', $candidateHost) === 1) {
			$host = $candidateHost;
		}
	}

	$id = '';
	if (preg_match('/[?&]id=(\d+)/i', $resolved, $idMatch) === 1) {
		$id = (string) ($idMatch[1] ?? '');
	}

	if ($id === '') {
		return '';
	}

	return 'https://' . $host . '/party.html?id=' . $id;
}

function extractPartyJoinAvailabilityFromHtml(string $html, string $baseUrl): array
{
	$result = [
		'partyName' => '',
		'joinDetected' => false,
		'hasJoinForm' => false,
		'hasJoinButton' => false,
		'joinActionUrl' => '',
		'joinMethod' => '',
		'joinFields' => [],
		'joinIndicator' => '',
		'leaveDetected' => false,
		'hasLeaveForm' => false,
		'leaveActionUrl' => '',
		'leaveMethod' => '',
		'leaveFields' => [],
	];

	if (trim($html) === '') {
		return $result;
	}

	$dom = new DOMDocument();
	libxml_use_internal_errors(true);
	$dom->loadHTML($html);
	libxml_clear_errors();
	$xpath = new DOMXPath($dom);

	$partyTitleNode = $xpath->query('//h1[1]')->item(0);
	if ($partyTitleNode instanceof DOMElement) {
		$result['partyName'] = compactNodeText((string) $partyTitleNode->textContent);
	}

	$formNodes = $xpath->query('//form[@id="command" and contains(translate(@action, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "partystatistics") and .//input[@name="action" and translate(@value, "abcdefghijklmnopqrstuvwxyz", "ABCDEFGHIJKLMNOPQRSTUVWXYZ")="JOIN"]]');
	if (!($formNodes instanceof DOMNodeList) || $formNodes->length === 0) {
		$formNodes = $xpath->query('//form[contains(translate(@action, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "join") or .//input[@name="action" and contains(translate(@value, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "join")]]');
	}
	if ($formNodes && $formNodes->length > 0) {
		$formNode = $formNodes->item(0);
		if ($formNode instanceof DOMElement) {
			$result['hasJoinForm'] = true;
			$actionRaw = trim((string) $formNode->getAttribute('action'));
			$result['joinActionUrl'] = $actionRaw !== '' ? resolveUrl($baseUrl, $actionRaw) : '';
			$method = strtoupper(trim((string) $formNode->getAttribute('method')));
			$result['joinMethod'] = $method !== '' ? $method : 'POST';

			$fields = [];
			$fieldNodes = $xpath->query('.//input | .//select | .//textarea', $formNode);
			if ($fieldNodes) {
				foreach ($fieldNodes as $fieldNode) {
					if (!($fieldNode instanceof DOMElement)) {
						continue;
					}

					$fieldName = trim((string) $fieldNode->getAttribute('name'));
					if ($fieldName === '') {
						continue;
					}

					$tagName = strtolower($fieldNode->tagName);
					$type = $tagName === 'input' ? strtolower(trim((string) $fieldNode->getAttribute('type'))) : $tagName;
					if ($type === '') {
						$type = 'text';
					}

					if ($tagName === 'input' && in_array($type, ['button', 'submit', 'image', 'file'], true)) {
						continue;
					}

					if (($type === 'checkbox' || $type === 'radio') && !$fieldNode->hasAttribute('checked')) {
						continue;
					}

					$fieldValue = '';
					if ($tagName === 'select') {
						$selectedOption = $xpath->query('.//option[@selected][1]', $fieldNode)->item(0);
						if ($selectedOption instanceof DOMElement) {
							$fieldValue = trim((string) $selectedOption->getAttribute('value'));
							if ($fieldValue === '') {
								$fieldValue = trim((string) $selectedOption->textContent);
							}
						} else {
							$firstOption = $xpath->query('.//option[1]', $fieldNode)->item(0);
							if ($firstOption instanceof DOMElement) {
								$fieldValue = trim((string) $firstOption->getAttribute('value'));
								if ($fieldValue === '') {
									$fieldValue = trim((string) $firstOption->textContent);
								}
							}
						}
					} elseif ($tagName === 'textarea') {
						$fieldValue = trim((string) $fieldNode->textContent);
					} else {
						$fieldValue = trim((string) $fieldNode->getAttribute('value'));
					}

					$fields[$fieldName] = $fieldValue;
				}
			}

			$result['joinFields'] = $fields;
			$hasJoinCommandPattern = strtoupper(trim((string) ($fields['action'] ?? ''))) === 'JOIN'
				&& preg_match('/^\d+$/', (string) ($fields['id'] ?? '')) === 1;
			$result['joinIndicator'] = $hasJoinCommandPattern ? 'join-command-form' : 'join-form';
		}
	}

	if (!$result['hasJoinForm']) {
		$buttonNodes = $xpath->query('//button[contains(translate(normalize-space(.), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "join") or contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "join") or contains(translate(@onclick, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "join")] | //a[contains(translate(normalize-space(.), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "join") or contains(translate(@href, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "joinParty") or contains(translate(@onclick, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "join")] | //input[@type="submit" and contains(translate(@value, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "join")]');
		if ($buttonNodes && $buttonNodes->length > 0) {
			$buttonNode = $buttonNodes->item(0);
			if ($buttonNode instanceof DOMElement) {
				$result['hasJoinButton'] = true;
				$hrefRaw = trim((string) $buttonNode->getAttribute('href'));
				if ($hrefRaw !== '') {
					$result['joinActionUrl'] = resolveUrl($baseUrl, $hrefRaw);
				}
				$result['joinIndicator'] = 'join-button';
			}
		}
	}

	if (!$result['hasJoinForm'] && !$result['hasJoinButton']) {
		$rawLower = strtolower($html);
		if (str_contains($rawLower, 'join party') || str_contains($rawLower, 'join this party') || str_contains($rawLower, 'joinparty')) {
			$result['joinIndicator'] = 'join-keyword-only';
		}
	}

	$leaveFormNode = $xpath->query('//form[@id="command" and contains(translate(@action, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "partystatistics") and .//input[@name="action" and translate(@value, "abcdefghijklmnopqrstuvwxyz", "ABCDEFGHIJKLMNOPQRSTUVWXYZ")="LEAVE"]]')->item(0);
	if ($leaveFormNode instanceof DOMElement) {
		$result['hasLeaveForm'] = true;
		$result['leaveDetected'] = true;
		$leaveActionRaw = trim((string) $leaveFormNode->getAttribute('action'));
		$result['leaveActionUrl'] = resolveUrl($baseUrl, $leaveActionRaw !== '' ? $leaveActionRaw : 'partyStatistics.html');
		$leaveMethod = strtoupper(trim((string) $leaveFormNode->getAttribute('method')));
		$result['leaveMethod'] = $leaveMethod !== '' ? $leaveMethod : 'POST';

		$leaveFields = [];
		$leaveFieldNodes = $xpath->query('.//input | .//select | .//textarea', $leaveFormNode);
		if ($leaveFieldNodes instanceof DOMNodeList) {
			foreach ($leaveFieldNodes as $leaveFieldNode) {
				if (!($leaveFieldNode instanceof DOMElement)) {
					continue;
				}

				$fieldName = trim((string) $leaveFieldNode->getAttribute('name'));
				if ($fieldName === '') {
					continue;
				}

				$tagName = strtolower($leaveFieldNode->tagName);
				$type = $tagName === 'input' ? strtolower(trim((string) $leaveFieldNode->getAttribute('type'))) : $tagName;
				if ($tagName === 'input' && in_array($type, ['button', 'submit', 'image', 'file'], true)) {
					continue;
				}

				if ($tagName === 'textarea') {
					$leaveFields[$fieldName] = trim((string) $leaveFieldNode->textContent);
					continue;
				}

				$leaveFields[$fieldName] = trim((string) $leaveFieldNode->getAttribute('value'));
			}
		}

		$result['leaveFields'] = $leaveFields;
	}

	$result['joinDetected'] = $result['hasJoinForm'] || $result['hasJoinButton'];

	return $result;
}

function normalizeRegionPageUrl(string $rawUrl, string $fallbackBaseUrl = ''): string
{
	if (trim($fallbackBaseUrl) === '') {
		$fallbackBaseUrl = serverUrl('index.html');
	}

	$raw = trim(html_entity_decode($rawUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
	if ($raw === '') {
		return '';
	}

	global $server;
	$host = (string) parse_url(serverUrl('index.html'), PHP_URL_HOST);
	if ($host === '') {
		$host = 'vara.e-sim.org';
	}
	if (preg_match('/^https?:\/\/([^\/]+)/i', $raw, $hostMatch) === 1) {
		$candidateHost = strtolower(trim((string) ($hostMatch[1] ?? '')));
		if (preg_match('/^[a-z0-9.-]+\.e-sim\.org$/', $candidateHost) === 1) {
			$host = $candidateHost;
		}
	}

	if (preg_match('/^\d+$/', $raw) === 1) {
		return 'https://' . $host . '/region.html?id=' . $raw;
	}

	$resolved = preg_match('/^https?:\/\//i', $raw) === 1
		? $raw
		: resolveUrl($fallbackBaseUrl, $raw);

	if (preg_match('/^https?:\/\/([^\/]+)/i', $resolved, $hostMatch) === 1) {
		$candidateHost = strtolower(trim((string) ($hostMatch[1] ?? '')));
		if (preg_match('/^[a-z0-9.-]+\.e-sim\.org$/', $candidateHost) === 1) {
			$host = $candidateHost;
		}
	}

	$id = '';
	if (preg_match('/[?&]id=(\d+)/i', $resolved, $idMatch) === 1) {
		$id = (string) ($idMatch[1] ?? '');
	}

	if ($id === '') {
		return '';
	}

	return 'https://' . $host . '/region.html?id=' . $id;
}

function extractRegionSummaryFromRegionHtml(string $regionHtml, string $expectedRegionId = ''): array
{
	$result = [
		'regionName' => '',
		'currentOwner' => '',
		'rightfulOwner' => '',
		'resource' => '',
	];

	if (trim($regionHtml) === '') {
		return $result;
	}

	$dom = new DOMDocument();
	libxml_use_internal_errors(true);
	$dom->loadHTML($regionHtml);
	libxml_clear_errors();
	$xpath = new DOMXPath($dom);
	$scope = $xpath->query('(//div[contains(concat(" ", normalize-space(@class), " "), " regionContainer ")]//div[contains(concat(" ", normalize-space(@class), " "), " regionViewTiles ")])[1]')->item(0);
	if (!($scope instanceof DOMElement)) {
		$scope = $xpath->query('(//div[contains(concat(" ", normalize-space(@class), " "), " regionViewTiles ")])[1]')->item(0);
	}

	$regionNameNode = $scope instanceof DOMElement
		? $xpath->query('(.//*[contains(concat(" ", normalize-space(@class), " "), " darkTableLookLikeHeader ") and normalize-space(text())!=""])[1]', $scope)->item(0)
		: null;
	if (!($regionNameNode instanceof DOMElement) && $scope instanceof DOMElement) {
		$regionNameNode = $xpath->query('(.//*[self::h1 or self::h2 or contains(concat(" ", normalize-space(@class), " "), " regionName ")])[1]', $scope)->item(0);
	}
	if (!($regionNameNode instanceof DOMElement)) {
		$regionNameNode = $xpath->query('(//*[@id="esim-layout"]//*[self::h1 or self::h2 or contains(concat(" ", normalize-space(@class), " "), " regionName ")])[1]')->item(0);
	}
	if ($regionNameNode instanceof DOMElement) {
		$result['regionName'] = compactNodeText((string) $regionNameNode->textContent);
	}

	$tiles = $scope instanceof DOMElement
		? $xpath->query('.//div[contains(concat(" ", normalize-space(@class), " "), " regionInfoTile ")]', $scope)
		: null;
	if ($tiles) {
		foreach ($tiles as $tileNode) {
			if (!($tileNode instanceof DOMElement)) {
				continue;
			}

			$labelNode = $xpath->query('./div[1]', $tileNode)->item(0);
			$valueNode = $xpath->query('./div[2]', $tileNode)->item(0);
			$labelText = $labelNode instanceof DOMElement ? compactNodeText((string) $labelNode->textContent) : '';
			$valueText = $valueNode instanceof DOMElement ? compactNodeText((string) $valueNode->textContent) : compactNodeText((string) $tileNode->textContent);
			$normalized = normalizeSidebarLabel($labelText !== '' ? $labelText : $valueText);

			if ($result['currentOwner'] === '' && (str_contains($normalized, 'current owner') || str_contains($normalized, 'dueno actual') || str_contains($normalized, 'dueño actual'))) {
				$ownerNode = $valueNode instanceof DOMElement
					? $xpath->query('.//*[contains(@class,"countryNameTranslated") or self::a][1]', $valueNode)->item(0)
					: null;
				$result['currentOwner'] = $ownerNode instanceof DOMElement
					? compactNodeText((string) $ownerNode->textContent)
					: $valueText;
				continue;
			}

			if ($result['rightfulOwner'] === '' && (str_contains($normalized, 'rightful owner') || str_contains($normalized, 'propietario legitimo') || str_contains($normalized, 'propietario legítimo'))) {
				$ownerNode = $valueNode instanceof DOMElement
					? $xpath->query('.//*[contains(@class,"countryNameTranslated") or self::a][1]', $valueNode)->item(0)
					: null;
				$result['rightfulOwner'] = $ownerNode instanceof DOMElement
					? compactNodeText((string) $ownerNode->textContent)
					: $valueText;
				continue;
			}

			if ($result['resource'] === '' && (str_contains($normalized, 'resource') || str_contains($normalized, 'recurso'))) {
				$resourceNode = $valueNode instanceof DOMElement
					? $xpath->query('.//*[@title][1]', $valueNode)->item(0)
					: null;
				if ($resourceNode instanceof DOMElement) {
					$result['resource'] = compactNodeText((string) $resourceNode->getAttribute('title'));
				}
				if ($result['resource'] === '') {
					$result['resource'] = $valueText;
				}
			}
		}
	}

	if ($result['regionName'] === '' && preg_match('/<title>\s*([^<]+?)\s*\|/i', $regionHtml, $m) === 1) {
		$result['regionName'] = compactNodeText((string) ($m[1] ?? ''));
	}

	if ($result['regionName'] === '' && $expectedRegionId !== '' && preg_match('/^\d+$/', $expectedRegionId) === 1) {
		$result['regionName'] = 'Region #' . $expectedRegionId;
	}

	return $result;
}

function extractCompanyJobOffersFromCompanyHtml(string $html, string $baseUrl): array
{
	$result = [
		'companyName' => '',
		'offers' => [],
	];

	if (trim($html) === '') {
		return $result;
	}

	$dom = new DOMDocument();
	libxml_use_internal_errors(true);
	$dom->loadHTML($html);
	libxml_clear_errors();
	$xpath = new DOMXPath($dom);

	$companyNameNode = $xpath->query('//*[@id="companyMenu"]//h1[1]')->item(0);
	if (!($companyNameNode instanceof DOMElement)) {
		$companyNameNode = $xpath->query('//h1[1]')->item(0);
	}
	if ($companyNameNode instanceof DOMElement) {
		$result['companyName'] = compactNodeText((string) $companyNameNode->textContent);
	}

	$offers = [];
	$seenOfferIds = [];
	$formNodes = $xpath->query('//form[contains(concat(" ", normalize-space(@class), " "), " job-offer-form ") and (contains(@action,"jobMarket") or .//*[@data-id]) ]');
	if (!$formNodes || $formNodes->length === 0) {
		$formNodes = $xpath->query('//form[contains(@action,"jobMarket") and (.//input[@name="id"] or .//*[@data-id])]');
	}

	if ($formNodes) {
		foreach ($formNodes as $formNode) {
			if (!($formNode instanceof DOMElement)) {
				continue;
			}

			$actionRaw = trim((string) $formNode->getAttribute('action'));
			$actionUrl = resolveUrl($baseUrl, $actionRaw !== '' ? $actionRaw : 'jobMarket.html');

			$buttonNode = $xpath->query('.//button[@data-id or contains(@class, "btn-buy")][1]', $formNode)->item(0);
			$offerId = '';
			$countryId = '';
			if ($buttonNode instanceof DOMElement) {
				$offerId = trim((string) $buttonNode->getAttribute('data-id'));
				$countryId = trim((string) $buttonNode->getAttribute('data-country-id'));
			}

			if ($offerId === '') {
				$offerIdNode = $xpath->query('.//input[@name="id"][1]', $formNode)->item(0);
				if ($offerIdNode instanceof DOMElement) {
					$offerId = trim((string) $offerIdNode->getAttribute('value'));
				}
			}

			if ($countryId === '') {
				$countryNode = $xpath->query('.//input[@name="countryId"][1]', $formNode)->item(0);
				if ($countryNode instanceof DOMElement) {
					$countryId = trim((string) $countryNode->getAttribute('value'));
				}
			}

			if ($offerId === '' || isset($seenOfferIds[$offerId])) {
				continue;
			}

			$salaryText = '';
			$salaryNode = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " job-offer-salary ")][1]', $formNode)->item(0);
			if ($salaryNode instanceof DOMElement) {
				$salaryText = compactNodeText((string) $salaryNode->textContent);
			}

			$minSkillText = '';
			$skillNode = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " job-offer-economicSkill ")][1]', $formNode)->item(0);
			if ($skillNode instanceof DOMElement) {
				if (preg_match('/(\d+(?:[\.,]\d+)?)/', (string) $skillNode->textContent, $skillMatch) === 1) {
					$minSkillText = str_replace(',', '.', (string) ($skillMatch[1] ?? ''));
				}
			}

			$offerCompany = '';
			$companyNode = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " job-offer-company ")]//a[contains(@href,"company.html")][1]', $formNode)->item(0);
			if ($companyNode instanceof DOMElement) {
				$offerCompany = compactNodeText((string) $companyNode->textContent);
			}

			$employer = '';
			$employerNode = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " job-offer-employer ")][1]', $formNode)->item(0);
			if ($employerNode instanceof DOMElement) {
				$employer = compactNodeText((string) $employerNode->textContent);
			}

			$productLabel = '';
			$productImageNode = $xpath->query('.//img[contains(concat(" ", normalize-space(@class), " "), " newProduct ")][1]', $formNode)->item(0);
			if ($productImageNode instanceof DOMElement) {
				$productLabel = trim((string) $productImageNode->getAttribute('title'));
			}
			$qualityNode = $xpath->query('.//img[contains(@src, "/q")][1]', $formNode)->item(0);
			if ($qualityNode instanceof DOMElement) {
				$qualitySrc = (string) $qualityNode->getAttribute('src');
				if (preg_match('/q(\d+)/i', $qualitySrc, $qMatch) === 1) {
					$productLabel = trim($productLabel . ' Q' . (string) ($qMatch[1] ?? ''));
				}
			}

			$seenOfferIds[$offerId] = true;
			$offers[] = [
				'id' => $offerId,
				'countryId' => $countryId,
				'applyActionUrl' => $actionUrl,
				'refererUrl' => $baseUrl,
				'product' => $productLabel,
				'salary' => $salaryText,
				'minSkill' => $minSkillText,
				'company' => $offerCompany,
				'employer' => $employer,
			];
		}
	}

	$result['offers'] = $offers;
	return $result;
}

function submitJobOfferApply($ch, string $refererUrl, array $defaultHeaders, string $actionUrl, string $offerId, string $countryId = ''): array
{
	$fallbackRef = serverUrl('jobMarket.html');
	$safeReferer = $refererUrl !== '' ? $refererUrl : $fallbackRef;

	$targetUrl = $actionUrl;
	if (preg_match('/[?&]id=\d+/i', $targetUrl) !== 1) {
		$targetUrl .= (str_contains($targetUrl, '?') ? '&' : '?') . 'id=' . rawurlencode($offerId);
	}

	$postPayload = ['id' => $offerId];
	if ($countryId !== '' && preg_match('/^\d+$/', $countryId) === 1) {
		$postPayload['countryId'] = $countryId;
	}

	$postHeaders = [
		'Content-Type: application/x-www-form-urlencoded',
		'Origin: ' . rtrim(serverUrl(''), '/'),
		'Referer: ' . $safeReferer,
		'Accept: application/json, text/plain, */*',
	];

	return curlRequest($ch, $targetUrl, [
		CURLOPT_POST => true,
		CURLOPT_POSTFIELDS => http_build_query($postPayload),
		CURLOPT_HTTPHEADER => array_merge($defaultHeaders, $postHeaders),
	]);
}

function submitConsumableTask($ch, string $refererUrl, array $defaultHeaders, string $actionPath, string $quality): array
{
	$fallbackRef = serverUrl('index.html');
	$safeReferer = $refererUrl !== '' ? $refererUrl : $fallbackRef;
	$actionUrl = resolveUrl($safeReferer, $actionPath);
	$postHeaders = [
		'Content-Type: application/x-www-form-urlencoded',
		'Origin: ' . rtrim(serverUrl(''), '/'),
		'Referer: ' . $safeReferer,
		'Accept: application/json, text/plain, */*',
	];

	return curlRequest($ch, $actionUrl, [
		CURLOPT_POST => true,
		CURLOPT_POSTFIELDS => http_build_query(['quality' => $quality]),
		CURLOPT_HTTPHEADER => array_merge($defaultHeaders, $postHeaders),
	]);
}

function submitTravelTask($ch, string $refererUrl, array $defaultHeaders, string $actionUrl, array $payload): array
{
	$fallbackRef = serverUrl('index.html');
	$safeReferer = $refererUrl !== '' ? $refererUrl : $fallbackRef;
	$postHeaders = [
		'Content-Type: application/x-www-form-urlencoded',
		'Origin: ' . rtrim(serverUrl(''), '/'),
		'Referer: ' . $safeReferer,
		'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
	];

	return curlRequest($ch, $actionUrl, [
		CURLOPT_POST => true,
		CURLOPT_POSTFIELDS => http_build_query($payload),
		CURLOPT_HTTPHEADER => array_merge($defaultHeaders, $postHeaders),
	]);
}

function normalizeEnergyString(string $value): string
{
	$clean = trim((string) preg_replace('/\s+/', '', $value));
	$clean = str_replace(',', '.', $clean);
	if (preg_match('/^\d+(?:\.\d+)?$/', $clean)) {
		return $clean;
	}

	if (preg_match('/(\d+(?:[\.,]\d+)?)/', $value, $m)) {
		return str_replace(',', '.', (string) ($m[1] ?? ''));
	}

	return '';
}

function extractEnergyFromAnyResponse(string $body): string
{
	if (trim($body) === '') {
		return '';
	}

	$decoded = json_decode($body, true);
	if (is_array($decoded) && isset($decoded['wellness'])) {
		return normalizeEnergyString((string) $decoded['wellness']);
	}

	if (preg_match('/id=["\']healthUpdate["\'][^>]*>\s*([0-9]+(?:[\.,][0-9]+)?)/i', $body, $m)) {
		return normalizeEnergyString((string) ($m[1] ?? ''));
	}

	if (preg_match('/id=["\']actualHealth["\'][^>]*>\s*([0-9]+(?:[\.,][0-9]+)?)/i', $body, $m)) {
		return normalizeEnergyString((string) ($m[1] ?? ''));
	}

	if (preg_match('/id=["\']actualHP["\'][^>]*>\s*([0-9]+(?:[\.,][0-9]+)?)/i', $body, $m)) {
		return normalizeEnergyString((string) ($m[1] ?? ''));
	}

	return '';
}

function extractDamageFromAnyResponse(string $body): string
{
	if (trim($body) === '') {
		return '';
	}

	$decoded = json_decode($body, true);
	if (is_array($decoded)) {
		foreach (['damage', 'dmg', 'hitDamage', 'totalDamage', 'newDamage'] as $key) {
			if (isset($decoded[$key])) {
				$value = normalizeDamageString((string) $decoded[$key]);
				if ($value !== '') {
					return $value;
				}
			}
		}
	}

	if (preg_match('/"(?:damage|dmg|hitDamage|totalDamage|newDamage)"\s*:\s*"?([0-9][0-9\.,]*)"?/i', $body, $m)) {
		$value = normalizeDamageString((string) ($m[1] ?? ''));
		if ($value !== '') {
			return $value;
		}
	}

	if (preg_match('/(?:damage|dano|daño)\s*[:=]\s*([0-9][0-9\.,]*)/iu', $body, $m)) {
		$value = normalizeDamageString((string) ($m[1] ?? ''));
		if ($value !== '') {
			return $value;
		}
	}

	return '';
}

function normalizeDamageString(string $value): string
{
	$clean = trim((string) preg_replace('/[^0-9\.,]/', '', $value));
	if ($clean === '') {
		return '';
	}

	if (str_contains($clean, ',') && str_contains($clean, '.')) {
		$lastComma = strrpos($clean, ',');
		$lastDot = strrpos($clean, '.');
		if ($lastComma !== false && $lastDot !== false) {
			if ($lastComma > $lastDot) {
				$clean = str_replace('.', '', $clean);
				$clean = str_replace(',', '.', $clean);
			} else {
				$clean = str_replace(',', '', $clean);
			}
		}
	} elseif (str_contains($clean, ',')) {
		if (preg_match('/,\d{1,2}$/', $clean)) {
			$clean = str_replace(',', '.', $clean);
		} else {
			$clean = str_replace(',', '', $clean);
		}
	}

	if (!preg_match('/^\d+(?:\.\d+)?$/', $clean)) {
		return '';
	}

	return $clean;
}

function formatAmountWithThousands(string $value, int $decimals = 2): string
{
	$normalized = normalizeDamageString($value);
	if ($normalized === '') {
		return compactNodeText($value);
	}

	return number_format((float) $normalized, $decimals, '.', ',');
}

function resolveUrl(string $baseUrl, string $url): string
{
	if ($url === '') {
		return $baseUrl;
	}

	if (preg_match('/^https?:\/\//i', $url)) {
		return $url;
	}

	$base = parse_url($baseUrl);
	if (!is_array($base) || !isset($base['scheme'], $base['host'])) {
		return $url;
	}

	$origin = $base['scheme'] . '://' . $base['host'];
	if (str_starts_with($url, '/')) {
		return $origin . $url;
	}

	$path = isset($base['path']) ? (string) $base['path'] : '/';
	$dir = rtrim(str_replace('\\', '/', dirname($path)), '/');
	if ($dir === '') {
		$dir = '/';
	}

	return rtrim($origin . $dir, '/') . '/' . ltrim($url, '/');
}

function extractHostFromUrl(string $url): string
{
	global $server;
	$parts = parse_url($url);
	return (string) ($parts['host'] ?? $server . '.e-sim.org');
}

function looksAuthenticated(string $html): bool
{
	$source = strtolower($html);
	$hasLogout = strpos($source, 'logout.html') !== false;
	$hasUserName = strpos($source, 'id="username"') !== false;
	$stillHasLoginForm = strpos($source, 'name="login"') !== false && strpos($source, 'name="password"') !== false;

	return ($hasLogout || $hasUserName) && !$stillHasLoginForm;
}

function extractLoggedPlayerInfo(string $html): array
{
	$info = [
		'name' => '',
		'citizenId' => '',
		'profileUrl' => '',
		'date' => '',
		'time' => '',
		'day' => '',
		'level' => '',
		'rankTitle' => '',
		'experience' => '',
		'attackRank' => '',
		'energy' => '',
		'economicSkill' => '',
		'strength' => '',
		'location' => '',
		'found' => false,
	];

	if (trim($html) === '') {
		return $info;
	}

	$dom = new DOMDocument();
	libxml_use_internal_errors(true);
	$dom->loadHTML($html);
	libxml_clear_errors();

	$xpath = new DOMXPath($dom);

	$nameNode = $xpath->query('//*[@id="userName"]')->item(0);
	if ($nameNode instanceof DOMElement) {
		$info['name'] = trim($nameNode->textContent);
		$href = trim((string) $nameNode->getAttribute('href'));
		if ($href !== '') {
			$info['profileUrl'] = resolveUrl(serverUrl('index.html'), $href);
			if (preg_match('/[?&]id=(\d+)/i', $href, $m)) {
				$info['citizenId'] = (string) $m[1];
			}
		}
	}

	$levelNode = $xpath->query('//*[@id="levelMission"]//span[last()]')->item(0);
	if ($levelNode instanceof DOMElement) {
		$info['level'] = trim($levelNode->textContent);
	}

	$rankTitleNode = $xpath->query('//*[@id="currRankText"]//span[last()]')->item(0);
	if ($rankTitleNode instanceof DOMElement) {
		$info['rankTitle'] = trim($rankTitleNode->textContent);
	}

	$xpNode = $xpath->query('//*[@id="actualXp"]')->item(0);
	if ($xpNode instanceof DOMElement) {
		$info['experience'] = trim($xpNode->textContent);
	}

	$attackRankNode = $xpath->query('//*[@id="actualRank"]')->item(0);
	if ($attackRankNode instanceof DOMElement) {
		$info['attackRank'] = trim($attackRankNode->textContent);
	}

	$energyNode = $xpath->query('//*[@id="actualHealth" or @id="actualHP"]')->item(0);
	if ($energyNode instanceof DOMElement) {
		$info['energy'] = normalizeEnergyString((string) $energyNode->textContent);
	}

	$clockNode = $xpath->query('//div[contains(@class,"sidebar-clock")]//b[contains(@class,"time")]')->item(0);
	if (!($clockNode instanceof DOMElement)) {
		$clockNode = $xpath->query('//b[contains(@class,"time")]')->item(0);
	}

	if ($clockNode instanceof DOMElement) {
		$clockText = trim($clockNode->textContent);
		if (preg_match('/^(\d{1,2}\/\d{1,2}\/\d{4})\s*,?\s*(\d{1,2}:\d{2}(?::\d{2})?)$/', $clockText, $m)) {
			$info['date'] = trim((string) $m[1]);
			$info['time'] = trim((string) $m[2]);
		} elseif (preg_match('/^(\d{1,2}:\d{2}(?::\d{2})?)\s+(.+)$/', $clockText, $m)) {
			$info['time'] = trim((string) $m[1]);
			$info['date'] = trim((string) $m[2]);
		} else {
			$info['date'] = $clockText;
		}
	}

	$dayNodes = $xpath->query('//div[contains(@class,"sidebar-clock")]//b | //b');
	if ($dayNodes) {
		foreach ($dayNodes as $dayNode) {
			if (!($dayNode instanceof DOMElement)) {
				continue;
			}

			$dayText = trim((string) $dayNode->textContent);
			if (!preg_match('/^(day|dia|día)\s+\d+/iu', $dayText)) {
				continue;
			}

			$info['day'] = $dayText;
			break;
		}
	}

	$info['economicSkill'] = findSidebarLabeledValue($xpath, ['Economic skill', 'Habilidad económica']);
	$info['strength'] = findSidebarLabeledValue($xpath, ['Strength', 'Fortaleza']);
	$info['location'] = findSidebarLocation($xpath);

	$info['found'] = $info['name'] !== '' || $info['citizenId'] !== '';
	return $info;
}

function normalizeSidebarLabel(string $value): string
{
	$normalized = trim(mb_strtolower($value, 'UTF-8'));
	if ($normalized === '') {
		return '';
	}

	$normalized = rtrim($normalized, ':');
	$normalized = str_replace(['á', 'é', 'í', 'ó', 'ú', 'ü'], ['a', 'e', 'i', 'o', 'u', 'u'], $normalized);
	$normalized = preg_replace('/\s+/', ' ', $normalized);
	return trim((string) $normalized);
}

function findSidebarLabeledValue(DOMXPath $xpath, $labels): string
{
	$labelList = is_array($labels) ? $labels : [$labels];
	$expectedSet = [];
	foreach ($labelList as $label) {
		$normalized = normalizeSidebarLabel((string) $label);
		if ($normalized !== '') {
			$expectedSet[$normalized] = true;
		}
	}

	if (!$expectedSet) {
		return '';
	}

	$labelSpans = $xpath->query('//span');
	if (!$labelSpans) {
		return '';
	}

	foreach ($labelSpans as $labelSpan) {
		if (!($labelSpan instanceof DOMElement)) {
			continue;
		}

		$current = normalizeSidebarLabel((string) $labelSpan->textContent);
		if (!isset($expectedSet[$current])) {
			continue;
		}

		$nextSpan = $xpath->query('following-sibling::span[1]', $labelSpan)->item(0);
		if ($nextSpan instanceof DOMElement) {
			$spanValue = trim((string) preg_replace('/\s+/', ' ', (string) $nextSpan->textContent));
			if ($spanValue !== '') {
				return $spanValue;
			}
		}

		$nextElement = $xpath->query('following-sibling::*[1]', $labelSpan)->item(0);
		if ($nextElement instanceof DOMElement) {
			$elementValue = trim((string) preg_replace('/\s+/', ' ', (string) $nextElement->textContent));
			if ($elementValue !== '') {
				return $elementValue;
			}
		}
	}

	return '';
}

function findSidebarLocation(DOMXPath $xpath): string
{
	$fromLabeledValue = findSidebarLabeledValue($xpath, ['Location', 'Ubicación']);
	if ($fromLabeledValue !== '') {
		return $fromLabeledValue;
	}

	$nodes = $xpath->query('//div[contains(@class,"sidebar-labeled-information")]');
	if (!$nodes) {
		return '';
	}

	foreach ($nodes as $node) {
		if (!($node instanceof DOMElement)) {
			continue;
		}

		$labelNode = $xpath->query('.//span', $node)->item(0);
		if (!($labelNode instanceof DOMElement)) {
			continue;
		}

		$label = normalizeSidebarLabel((string) $labelNode->textContent);
		if ($label !== 'location' && $label !== 'ubicacion') {
			continue;
		}

		$link = $xpath->query('.//a', $node)->item(0);
		if ($link instanceof DOMElement) {
			$text = trim(preg_replace('/\s+/', ' ', (string) $link->textContent));
			if ($text !== '') {
				return $text;
			}
		}

		$text = trim(preg_replace('/\s+/', ' ', (string) $node->textContent));
		$text = preg_replace('/^Location\s*:?/i', '', $text);
		return trim((string) $text);
	}

	return '';
}

function extractAvailableBattles(string $html, string $baseUrl): array
{
	if (trim($html) === '') {
		return [];
	}

	$dom = new DOMDocument();
	libxml_use_internal_errors(true);
	$dom->loadHTML($html);
	libxml_clear_errors();

	$xpath = new DOMXPath($dom);
	$items = [];
	$seen = [];

	$gridNodes = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " battleGrid ")]');
	if ($gridNodes) {
		foreach ($gridNodes as $gridNode) {
			if (!($gridNode instanceof DOMElement)) {
				continue;
			}

			$linkNode = $xpath->query('.//div[contains(concat(" ", normalize-space(@class), " "), " battleHeader ")]//a[contains(@href, "battle.html")][1]', $gridNode)->item(0);
			$hrefRaw = $linkNode instanceof DOMElement ? trim((string) $linkNode->getAttribute('href')) : '';
			if ($hrefRaw === '') {
				$hrefRaw = trim((string) $gridNode->getAttribute('data-link'));
			}

			if ($hrefRaw === '') {
				continue;
			}

			$url = resolveUrl($baseUrl !== '' ? $baseUrl : serverUrl('index.html'), $hrefRaw);
			if (isset($seen[$url])) {
				continue;
			}

			$title = $linkNode instanceof DOMElement
				? trim((string) preg_replace('/\s+/', ' ', (string) $linkNode->textContent))
				: '';
			if ($title === '') {
				$title = 'Battle';
			}

			$matchupText = '';
			$matchupNode = $xpath->query('.//div[contains(concat(" ", normalize-space(@class), " "), " battleHeader ")]//em[1]', $gridNode)->item(0);
			if ($matchupNode instanceof DOMElement) {
				$matchupText = compactNodeText((string) $matchupNode->textContent);
			}

			$typeLabel = '';
			$typeNode = $xpath->query('.//div[contains(concat(" ", normalize-space(@class), " "), " battleHeader ")]//i[1]', $gridNode)->item(0);
			if ($typeNode instanceof DOMElement) {
				$typeLabel = compactNodeText((string) ($typeNode->getAttribute('data-hover') !== '' ? $typeNode->getAttribute('data-hover') : $typeNode->getAttribute('title')));
			}

			$participants = extractBattleParticipantsFromText($matchupText);

			$seen[$url] = true;
			$items[] = [
				'title' => $title,
				'url' => $url,
				'typeLabel' => $typeLabel,
				'matchupText' => $matchupText,
				'countryA' => (string) ($participants['countryA'] ?? ''),
				'countryB' => (string) ($participants['countryB'] ?? ''),
			];

			if (count($items) >= 30) {
				return $items;
			}
		}
	}

	$anchors = $xpath->query('//a[contains(@href, "battle.html") or contains(@href, "battleStatistics") or contains(@href, "battleId=")]');
	if (!$anchors) {
		return [];
	}

	foreach ($anchors as $anchor) {
		if (!($anchor instanceof DOMElement)) {
			continue;
		}

		$hrefRaw = trim((string) $anchor->getAttribute('href'));
		if ($hrefRaw === '') {
			continue;
		}

		$url = resolveUrl($baseUrl !== '' ? $baseUrl : serverUrl('index.html'), $hrefRaw);
		if (isset($seen[$url])) {
			continue;
		}

		$title = trim((string) preg_replace('/\s+/', ' ', (string) $anchor->textContent));
		if ($title === '') {
			$title = 'Battle';
		}

		$participants = extractBattleParticipantsFromText($title);

		$seen[$url] = true;
		$items[] = [
			'title' => $title,
			'url' => $url,
			'typeLabel' => '',
			'matchupText' => '',
			'countryA' => (string) ($participants['countryA'] ?? ''),
			'countryB' => (string) ($participants['countryB'] ?? ''),
		];

		if (count($items) >= 30) {
			break;
		}
	}

	return $items;
}

function extractBattleParticipantsFromText(string $text): array
{
	$normalized = compactNodeText($text);
	if ($normalized === '') {
		return ['countryA' => '', 'countryB' => ''];
	}

	if (preg_match('/^(.+?)\s+(?:vs\.?|versus|v\.)\s+(.+)$/iu', $normalized, $m) === 1) {
		$left = compactNodeText((string) ($m[1] ?? ''));
		$right = compactNodeText((string) ($m[2] ?? ''));
		if ($left !== '' && $right !== '' && $left !== $right) {
			return ['countryA' => $left, 'countryB' => $right];
		}
	}

	return ['countryA' => '', 'countryB' => ''];
}

function isPracticeBattleItem(array $battleItem): bool
{
	$candidates = [
		(string) ($battleItem['title'] ?? ''),
		(string) ($battleItem['matchupText'] ?? ''),
		(string) ($battleItem['typeLabel'] ?? ''),
	];

	foreach ($candidates as $candidate) {
		if (stripos($candidate, 'practice battle') !== false) {
			return true;
		}
	}

	return false;
}

function attachBattleCombatDetailToItem(array $item, array $detail): array
{
	$item['detailsLoaded'] = true;
	$item['canFight'] = (bool) ($detail['canFight'] ?? false);
	$item['canFightDefender'] = (bool) ($detail['canFightDefender'] ?? false);
	$item['canFightAttacker'] = (bool) ($detail['canFightAttacker'] ?? false);
	$item['canChangeSide'] = (bool) ($detail['canChangeSide'] ?? false);
	$item['fightFor'] = (string) ($detail['fightFor'] ?? '');
	$item['countryA'] = (string) ($detail['countryA'] ?? '');
	$item['countryB'] = (string) ($detail['countryB'] ?? '');
	$item['battleType'] = (string) ($detail['battleType'] ?? 'unknown');
	$item['battleTypeLabel'] = (string) ($detail['battleTypeLabel'] ?? 'Tipo desconocido');
	$item['battleRegionId'] = (string) ($detail['battleRegionId'] ?? '');
	$item['travelDefenderRegionId'] = (string) ($detail['travelDefenderRegionId'] ?? '');
	$item['travelDefenderRegionName'] = (string) ($detail['travelDefenderRegionName'] ?? '');
	$item['travelDefenderRegionUrl'] = (string) ($detail['travelDefenderRegionUrl'] ?? '');
	$item['travelDefenderActionUrl'] = (string) ($detail['travelDefenderActionUrl'] ?? '');
	$item['travelDefenderCountryId'] = (string) ($detail['travelDefenderCountryId'] ?? '');
	$item['travelDefenderRedirectUrl'] = (string) ($detail['travelDefenderRedirectUrl'] ?? '');
	$item['travelDefenderTicketOptions'] = is_array($detail['travelDefenderTicketOptions'] ?? null) ? $detail['travelDefenderTicketOptions'] : [];
	$item['travelAttackerRegionId'] = (string) ($detail['travelAttackerRegionId'] ?? '');
	$item['travelAttackerRegionName'] = (string) ($detail['travelAttackerRegionName'] ?? '');
	$item['travelAttackerRegionUrl'] = (string) ($detail['travelAttackerRegionUrl'] ?? '');
	$item['travelAttackerActionUrl'] = (string) ($detail['travelAttackerActionUrl'] ?? '');
	$item['travelAttackerCountryId'] = (string) ($detail['travelAttackerCountryId'] ?? '');
	$item['travelAttackerRedirectUrl'] = (string) ($detail['travelAttackerRedirectUrl'] ?? '');
	$item['travelAttackerTicketOptions'] = is_array($detail['travelAttackerTicketOptions'] ?? null) ? $detail['travelAttackerTicketOptions'] : [];
	$item['playerSide'] = (string) ($detail['playerSide'] ?? '');
	$item['enemyCountry'] = (string) ($detail['enemyCountry'] ?? '');
	$item['fightRoundId'] = (string) ($detail['fightRoundId'] ?? '');
	$item['fightRequestUrl'] = (string) ($detail['fightRequestUrl'] ?? '');
	$item['fightIp'] = (string) ($detail['fightIp'] ?? '');
	$item['fightServerName'] = (string) ($detail['fightServerName'] ?? '');
	$item['fightCitizenId'] = (string) ($detail['fightCitizenId'] ?? '');
	$item['fightMyCitizenship'] = (string) ($detail['fightMyCitizenship'] ?? '');
	$item['fightCitizenRegion'] = (string) ($detail['fightCitizenRegion'] ?? '');
	$item['fightSecurityHash'] = (string) ($detail['fightSecurityHash'] ?? '');
	$item['fightMousePattern'] = (string) ($detail['fightMousePattern'] ?? '');
	$item['fightGameDay'] = (string) ($detail['fightGameDay'] ?? '');
	$item['weaponQ1'] = (string) ($detail['weaponQ1'] ?? '');
	$item['weaponQ5'] = (string) ($detail['weaponQ5'] ?? '');
	$item['fightActionUrl'] = (string) ($detail['fightActionUrl'] ?? '');
	$item['changeSideUrl'] = (string) ($detail['changeSideUrl'] ?? '');
	$item['detailHttpStatus'] = (int) ($detail['httpStatus'] ?? 0);
	$item['detailReason'] = (string) ($detail['reason'] ?? 'unknown');

	return $item;
}

function extractNotificationsListFromHtml(string $html, string $baseUrl): array
{
	if (trim($html) === '') {
		return [];
	}

	$dom = new DOMDocument();
	libxml_use_internal_errors(true);
	$dom->loadHTML($html);
	libxml_clear_errors();

	$xpath = new DOMXPath($dom);
	$items = [];
	$seen = [];

	// Primary strategy for e-sim notifications page structure.
	$alertNodes = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " alert ") and (@data-alertId or @data-alertid)]');
	if ($alertNodes) {
		foreach ($alertNodes as $alertNode) {
			if (!($alertNode instanceof DOMElement)) {
				continue;
			}

			$alertId = trim((string) $alertNode->getAttribute('data-alertId'));
			if ($alertId === '') {
				$alertId = trim((string) $alertNode->getAttribute('data-alertid'));
			}
			$type = '';
			$typeIconNode = $xpath->query('.//div[contains(concat(" ", normalize-space(@class), " "), " type ")]//i[@title][1]', $alertNode)->item(0);
			if ($typeIconNode instanceof DOMElement) {
				$type = compactNodeText((string) $typeIconNode->getAttribute('title'));
			}
			if ($type === '') {
				$typeTextNode = $xpath->query('.//div[contains(concat(" ", normalize-space(@class), " "), " type ")]/text()[normalize-space(.)!=""][1]', $alertNode)->item(0);
				if ($typeTextNode) {
					$type = compactNodeText((string) $typeTextNode->textContent);
				}
			}

			$whenRelative = '';
			$whenAbsolute = '';
			$dateNode = $xpath->query('.//div[contains(concat(" ", normalize-space(@class), " "), " date ")][1]', $alertNode)->item(0);
			if ($dateNode instanceof DOMElement) {
				$whenRelative = compactNodeText((string) $dateNode->textContent);
				$whenAbsolute = compactNodeText((string) $dateNode->getAttribute('data-hover'));
			}

			$messageNode = $xpath->query('.//div[contains(concat(" ", normalize-space(@class), " "), " message ")][1]', $alertNode)->item(0);
			$messageText = '';
			if ($messageNode instanceof DOMElement) {
				$messageText = compactNodeText((string) $messageNode->textContent);
			}
			if ($messageText === '') {
				$messageText = compactNodeText((string) $alertNode->textContent);
			}

			if ($messageText === '') {
				continue;
			}

			$linkUrl = '';
			$messageLinkNode = $messageNode instanceof DOMElement
				? $xpath->query('.//a[@href][1]', $messageNode)->item(0)
				: null;
			if (!($messageLinkNode instanceof DOMElement)) {
				$messageLinkNode = $xpath->query('.//a[@href][1]', $alertNode)->item(0);
			}
			if ($messageLinkNode instanceof DOMElement) {
				$href = trim((string) $messageLinkNode->getAttribute('href'));
				if ($href !== '' && $href !== '#') {
					$linkUrl = resolveUrl($baseUrl !== '' ? $baseUrl : serverUrl('notifications'), $href);
				}
			}

			$fullText = $type !== ''
				? ($type . ': ' . $messageText)
				: $messageText;
			$key = md5($alertId . '|' . $fullText);
			if (isset($seen[$key])) {
				continue;
			}

			$seen[$key] = true;
			$items[] = [
				'id' => $alertId,
				'type' => $type,
				'text' => $fullText,
				'when' => $whenRelative,
				'whenAbsolute' => $whenAbsolute,
				'url' => $linkUrl,
				'unread' => false,
			];

			if (count($items) >= 60) {
				break;
			}
		}
	}

	if (!empty($items)) {
		return $items;
	}

	// Fallback strategy for alternate markup variants.
	$queries = [
		'//li[contains(concat(" ", normalize-space(@class), " "), " notification ")]',
		'//div[contains(concat(" ", normalize-space(@class), " "), " notification ")]',
		'//*[contains(concat(" ", normalize-space(@class), " "), " notifications ")]//li[.//a or normalize-space(text())!=""]',
		'//*[contains(concat(" ", normalize-space(@class), " "), " notifications ")]//div[.//a or normalize-space(text())!=""]',
		'//*[@id="notifications"]//*[self::li or self::div][.//a or normalize-space(text())!=""]',
		'//table[contains(concat(" ", normalize-space(@class), " "), " notifications ")]//tr[.//a or normalize-space(text())!=""]',
		'//table[@id="notificationsTable"]//tr[.//a or normalize-space(text())!=""]',
	];

	foreach ($queries as $query) {
		$nodes = $xpath->query($query);
		if (!$nodes) {
			continue;
		}

		foreach ($nodes as $node) {
			if (!($node instanceof DOMElement)) {
				continue;
			}

			$classValue = strtolower(trim((string) $node->getAttribute('class')));
			if (str_contains($classValue, 'header') || str_contains($classValue, 'title') || str_contains($classValue, 'filter')) {
				continue;
			}

			$text = compactNodeText((string) $node->textContent);
			if ($text === '' || strlen($text) < 6) {
				continue;
			}

			$linkNode = $xpath->query('.//a[@href][1]', $node)->item(0);
			$linkUrl = '';
			if ($linkNode instanceof DOMElement) {
				$href = trim((string) $linkNode->getAttribute('href'));
				if ($href !== '' && $href !== '#') {
					$linkUrl = resolveUrl($baseUrl !== '' ? $baseUrl : serverUrl('notifications'), $href);
				}
			}

			$when = '';
			$timeNode = $xpath->query('.//time[1]', $node)->item(0);
			if ($timeNode instanceof DOMElement) {
				$when = compactNodeText((string) $timeNode->textContent);
			}
			if ($when === '') {
				$timeClassNode = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " time ") or contains(concat(" ", normalize-space(@class), " "), " date ")][1]', $node)->item(0);
				if ($timeClassNode instanceof DOMElement) {
					$when = compactNodeText((string) $timeClassNode->textContent);
				}
			}

			$unread = str_contains($classValue, 'new') || str_contains($classValue, 'unread');
			$key = md5($text . '|' . $linkUrl);
			if (isset($seen[$key])) {
				continue;
			}

			$seen[$key] = true;
			$items[] = [
				'text' => $text,
				'when' => $when,
				'url' => $linkUrl,
				'unread' => $unread,
			];

			if (count($items) >= 40) {
				break 2;
			}
		}

		if (!empty($items)) {
			break;
		}
	}

	return $items;
}

function extractDailiesFromHtml(string $html, string $baseUrl): array
{
	if (trim($html) === '') {
		return [];
	}

	$itemsFromJson = extractDailiesFromJsonPayload($html, $baseUrl);
	if (!empty($itemsFromJson)) {
		return $itemsFromJson;
	}

	$dom = new DOMDocument();
	libxml_use_internal_errors(true);
	$dom->loadHTML($html);
	libxml_clear_errors();
	$xpath = new DOMXPath($dom);

	$normalizedBaseUrl = $baseUrl !== '' ? $baseUrl : serverUrl('missionCenter/dailies');
	$nodes = $xpath->query('//div[starts-with(@id,"daily_") and contains(concat(" ", normalize-space(@class), " "), " daily ")]');
	$items = [];
	$seen = [];

	if (!$nodes) {
		return $items;
	}

	foreach ($nodes as $dailyNode) {
		if (!($dailyNode instanceof DOMElement)) {
			continue;
		}

		$dailyElementId = trim((string) $dailyNode->getAttribute('id'));
		$dailyId = '';
		if (preg_match('/daily_(\d+)/i', $dailyElementId, $dailyMatch) === 1) {
			$dailyId = trim((string) ($dailyMatch[1] ?? ''));
		}

		$dailyClass = trim((string) $dailyNode->getAttribute('class'));
		$descriptionNode = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " dailyDescription ")][1]', $dailyNode)->item(0);
		$description = $descriptionNode instanceof DOMElement
			? compactNodeText((string) $descriptionNode->textContent)
			: '';

		$progressTextNode = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " dailyProgressBarText ")][1]', $dailyNode)->item(0);
		$progressText = $progressTextNode instanceof DOMElement
			? compactNodeText((string) $progressTextNode->textContent)
			: '';

		$progressPercent = '';
		$progressNode = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " dailyProgress ")][1]', $dailyNode)->item(0);
		if ($progressNode instanceof DOMElement) {
			$style = trim((string) $progressNode->getAttribute('style'));
			if (preg_match('/width\s*:\s*([0-9]+(?:\.[0-9]+)?)%/i', $style, $progressMatch) === 1) {
				$progressPercent = trim((string) ($progressMatch[1] ?? ''));
			}
		}

		$buttonNode = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " dailyButton ")]//*[self::button or self::a or self::input][1]', $dailyNode)->item(0);
		$buttonText = '';
		$claimUrl = '';
		if ($buttonNode instanceof DOMElement) {
			$buttonText = compactNodeText((string) ($buttonNode->textContent !== '' ? $buttonNode->textContent : $buttonNode->getAttribute('value')));
			$claimUrl = detectActionUrlFromElement($xpath, $buttonNode, $normalizedBaseUrl);
		}

		$buttonTextLower = strtolower($buttonText);
		$looksClaimButton = $buttonTextLower !== ''
			&& (str_contains($buttonTextLower, 'claim') || str_contains($buttonTextLower, 'reclamar'));
		$isClaimable = hasClassToken($dailyNode, 'unclaimed') || $looksClaimButton;
		$isCompleted = $isClaimable;

		$rewards = [];
		$rewardNodes = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " dailyMissionRewardContainer ")]', $dailyNode);
		if ($rewardNodes) {
			foreach ($rewardNodes as $rewardNode) {
				if (!($rewardNode instanceof DOMElement)) {
					continue;
				}

				$amountNode = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " dailyMissionRewardAmount ")][1]', $rewardNode)->item(0);
				$rewardAmount = $amountNode instanceof DOMElement ? compactNodeText((string) $amountNode->textContent) : '';

				$typeNode = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " dailyMissionReward ")][1]', $rewardNode)->item(0);
				$rewardType = '';
				if ($typeNode instanceof DOMElement) {
					$rewardType = compactNodeText((string) $typeNode->getAttribute('data-hover'));
					if ($rewardType === '') {
						$rewardType = compactNodeText((string) $typeNode->getAttribute('class'));
					}
				}

				if ($rewardType === '' && $rewardAmount === '') {
					continue;
				}

				$rewards[] = [
					'type' => $rewardType,
					'amount' => $rewardAmount,
				];
			}
		}

		$itemKey = md5($dailyElementId . '|' . $description . '|' . $progressText);
		if (isset($seen[$itemKey])) {
			continue;
		}
		$seen[$itemKey] = true;

		$items[] = [
			'id' => $dailyElementId,
			'dailyId' => $dailyId,
			'description' => $description,
			'progressText' => $progressText,
			'progressPercent' => $progressPercent,
			'status' => $isClaimable ? 'UNCLAIMED' : 'ACTIVE',
			'isChest' => false,
			'isCompleted' => $isCompleted,
			'buttonText' => $buttonText,
			'isClaimable' => $isClaimable,
			'claimUrl' => $claimUrl,
			'className' => $dailyClass,
			'rewards' => $rewards,
		];

		if (count($items) >= 80) {
			break;
		}
	}

	return $items;
}

function extractDailiesFromJsonPayload(string $html, string $baseUrl): array
{
	$normalizedBaseUrl = $baseUrl !== '' ? $baseUrl : serverUrl('missionCenter/dailies');
	$defaultClaimUrl = resolveUrl($normalizedBaseUrl, 'missionCenter/dailies');
	$items = [];

	$dailiesKeyPos = strpos($html, '"dailies"');
	if ($dailiesKeyPos === false) {
		return [];
	}

	$dailiesArrayStart = strpos($html, '[', $dailiesKeyPos);
	if (!is_int($dailiesArrayStart) || $dailiesArrayStart < 0) {
		return [];
	}

	$dailiesJson = extractBalancedJsonFragment($html, $dailiesArrayStart, '[', ']');
	if ($dailiesJson === '') {
		return [];
	}

	$dailiesData = json_decode($dailiesJson, true);
	if (!is_array($dailiesData)) {
		return [];
	}

	$index = 0;
	foreach ($dailiesData as $dailyRaw) {
		if (!is_array($dailyRaw)) {
			continue;
		}

		$dailyId = preg_replace('/\D+/', '', (string) ($dailyRaw['id'] ?? ''));
		$description = compactNodeText((string) ($dailyRaw['description'] ?? ''));
		$progress = (int) ($dailyRaw['progress'] ?? 0);
		$maxProgress = (int) ($dailyRaw['max_progress'] ?? 0);
		$status = strtoupper(compactNodeText((string) ($dailyRaw['status'] ?? 'ACTIVE')));
		if ($status === '') {
			$status = 'ACTIVE';
		}
		$isClaimable = $status === 'UNCLAIMED';
		$isCompleted = $status === 'FINISHED' || $status === 'UNCLAIMED';
		$rewards = normalizeDailyRewards(is_array($dailyRaw['rewards'] ?? null) ? (array) $dailyRaw['rewards'] : []);
		$progressText = $maxProgress > 0 ? ($progress . ' / ' . $maxProgress) : (string) $progress;
		$progressPercent = $maxProgress > 0 ? (string) round(($progress / max(1, $maxProgress)) * 100, 2) : '';

		$itemId = $dailyId !== '' ? 'daily_' . $dailyId : 'daily_json_' . $index;
		$items[] = [
			'id' => $itemId,
			'dailyId' => $dailyId,
			'description' => $description !== '' ? $description : ('Mision diaria #' . ($index + 1)),
			'progressText' => $progressText,
			'progressPercent' => $progressPercent,
			'status' => $status,
			'isChest' => false,
			'isCompleted' => $isCompleted,
			'buttonText' => $status === 'UNCLAIMED' ? 'Reclamar' : ($status === 'FINISHED' ? 'Completada' : 'Ir'),
			'isClaimable' => $isClaimable,
			'claimUrl' => $dailyId !== '' ? (serverUrl('missionCenter/claimDailyReward?id=') . rawurlencode($dailyId)) : $defaultClaimUrl,
			'className' => 'daily-json ' . strtolower($status),
			'rewards' => $rewards,
		];

		$index++;
	}

	$dailyChestKeyPos = strpos($html, '"dailyChest"', $dailiesArrayStart);
	if ($dailyChestKeyPos !== false) {
		$dailyChestObjStart = strpos($html, '{', $dailyChestKeyPos);
		if (is_int($dailyChestObjStart) && $dailyChestObjStart >= 0) {
			$dailyChestJson = extractBalancedJsonFragment($html, $dailyChestObjStart, '{', '}');
			if ($dailyChestJson !== '') {
				$dailyChestData = json_decode($dailyChestJson, true);
				if (is_array($dailyChestData)) {
					$chestStatus = strtoupper(compactNodeText((string) ($dailyChestData['status'] ?? 'ACTIVE')));
					if ($chestStatus === '') {
						$chestStatus = 'ACTIVE';
					}
					$chestRewards = normalizeDailyRewards(is_array($dailyChestData['rewards'] ?? null) ? (array) $dailyChestData['rewards'] : []);
					$items[] = [
						'id' => 'daily_chest',
						'dailyId' => '',
						'description' => 'Daily chest (cofre diario)',
						'progressText' => 'Completa las 3 diarias para habilitarlo',
						'progressPercent' => '',
						'status' => $chestStatus,
						'isChest' => true,
						'isCompleted' => $chestStatus === 'FINISHED' || $chestStatus === 'UNCLAIMED',
						'buttonText' => $chestStatus === 'UNCLAIMED' ? 'Reclamar cofre' : ($chestStatus === 'FINISHED' ? 'Cofre completado' : 'Bloqueado'),
						'isClaimable' => $chestStatus === 'UNCLAIMED',
						'claimUrl' => $defaultClaimUrl,
						'className' => 'daily-chest-json ' . strtolower($chestStatus),
						'rewards' => $chestRewards,
					];
				}
			}
		}
	}

	return $items;
}

function normalizeDailyRewards(array $rewardsRaw): array
{
	$rewards = [];
	foreach ($rewardsRaw as $rewardRaw) {
		if (!is_array($rewardRaw)) {
			continue;
		}

		$type = compactNodeText((string) ($rewardRaw['type'] ?? ''));
		$amount = compactNodeText((string) (($rewardRaw['ammount'] ?? '') !== '' ? $rewardRaw['ammount'] : ($rewardRaw['amount'] ?? '')));
		if ($type === '' && $amount === '') {
			continue;
		}

		$rewards[] = [
			'type' => $type,
			'amount' => $amount,
		];
	}

	return $rewards;
}

function extractBalancedJsonFragment(string $source, int $startPos, string $openChar, string $closeChar): string
{
	$len = strlen($source);
	if ($startPos < 0 || $startPos >= $len) {
		return '';
	}

	if ((string) ($source[$startPos] ?? '') !== $openChar) {
		return '';
	}

	$depth = 0;
	$inString = false;
	$stringDelimiter = '';
	$escaped = false;

	for ($i = $startPos; $i < $len; $i++) {
		$ch = (string) ($source[$i] ?? '');

		if ($inString) {
			if ($escaped) {
				$escaped = false;
				continue;
			}
			if ($ch === '\\') {
				$escaped = true;
				continue;
			}
			if ($ch === $stringDelimiter) {
				$inString = false;
				$stringDelimiter = '';
			}
			continue;
		}

		if ($ch === '"' || $ch === "'") {
			$inString = true;
			$stringDelimiter = $ch;
			continue;
		}

		if ($ch === $openChar) {
			$depth++;
			continue;
		}

		if ($ch === $closeChar) {
			$depth--;
			if ($depth === 0) {
				return substr($source, $startPos, $i - $startPos + 1);
			}
		}
	}

	return '';
}

function submitDailyMissionClaim($ch, string $refererUrl, array $defaultHeaders, string $claimUrl, string $dailyId = ''): array
{
	$dailyId = preg_replace('/\D+/', '', trim($dailyId));
	$safeReferer = trim($refererUrl) !== '' ? trim($refererUrl) : serverUrl('missionCenter/dailies');

	$result = [
		'attempted' => true,
		'saved' => false,
		'reason' => 'dailies-claim-request-error',
		'httpStatus' => 0,
		'url' => '',
		'claimUrl' => trim($claimUrl),
		'dailyId' => $dailyId,
		'responseSnippet' => '',
		'error' => '',
	];

	if ($dailyId === '') {
		$result['reason'] = 'dailies-claim-id-missing';
		return $result;
	}

	$exactClaimUrl = serverUrl('missionCenter/claimDailyReward?id=') . rawurlencode($dailyId);
	$exactGetStep = curlRequest($ch, $exactClaimUrl, [
		CURLOPT_POST => false,
		CURLOPT_HTTPGET => true,
		CURLOPT_HTTPHEADER => array_merge($defaultHeaders, [
			'Referer: ' . $safeReferer,
			'Accept: application/json, text/plain, */*',
		]),
	]);

	$exactBody = (string) ($exactGetStep['body'] ?? '');
	$exactLower = strtolower($exactBody);
	$exactHttpOk = $exactGetStep['errno'] === 0
		&& (int) ($exactGetStep['statusCode'] ?? 0) >= 200
		&& (int) ($exactGetStep['statusCode'] ?? 0) < 400;
	$exactSuccess = preg_match('/(dailyrewardtaken|received your daily reward|reward was sent|you received|success)/i', $exactBody) === 1;
	$exactAlready = preg_match('/(dailyrewardalreadytaken|already\s+taken|already\s+claimed|reward\s+was\s+already\s+taken)/i', $exactBody) === 1;
	$exactError = preg_match('/(dailyrewarderror|error\s+occured|forbidden|not\s+logged|captcha|invalid|failed|exception)/i', $exactBody) === 1;

	$result['httpStatus'] = (int) ($exactGetStep['statusCode'] ?? 0);
	$result['url'] = (string) ($exactGetStep['effectiveUrl'] ?: $exactClaimUrl);
	$result['claimUrl'] = $exactClaimUrl;
	$result['responseSnippet'] = trim(substr(compactNodeText($exactBody), 0, 260));
	$result['error'] = (string) ($exactGetStep['error'] ?? '');

	if (!$exactHttpOk) {
		$result['reason'] = (int) ($exactGetStep['errno'] ?? 0) !== 0 ? 'dailies-claim-request-error' : 'dailies-claim-http-error';
		return $result;
	}

	if ($exactAlready) {
		$result['saved'] = true;
		$result['reason'] = 'dailies-reward-already-claimed';
		return $result;
	}

	if ($exactSuccess || (!$exactError && trim($exactLower) !== '')) {
		$result['saved'] = true;
		$result['reason'] = $exactSuccess ? 'dailies-reward-claimed' : 'dailies-claim-processed';
		return $result;
	}

	$result['reason'] = 'dailies-claim-rejected';
	return $result;
}

function extractStorageMoneyAccountsFromHtml(string $html): array
{
	if (trim($html) === '') {
		return [];
	}

	$dom = new DOMDocument();
	libxml_use_internal_errors(true);
	$dom->loadHTML($html);
	libxml_clear_errors();
	$xpath = new DOMXPath($dom);

	$accounts = [];
	$seen = [];
	$currencyNodes = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " currencyDiv ")]');
	if ($currencyNodes) {
		foreach ($currencyNodes as $currencyNode) {
			if (!($currencyNode instanceof DOMElement)) {
				continue;
			}

			$amountNode = $xpath->query('.//b[1]', $currencyNode)->item(0);
			$amountText = $amountNode instanceof DOMElement
				? trim((string) ($amountNode->getAttribute('title') !== '' ? $amountNode->getAttribute('title') : $amountNode->textContent))
				: '';

			$rawText = compactNodeText((string) $currencyNode->textContent);
			$currencyCode = '';
			if (preg_match('/\b([A-Z]{2,6}|Gold)\b/i', $rawText, $m) === 1) {
				$currencyCode = trim((string) ($m[1] ?? ''));
			}

			$flagNode = $xpath->query('.//*[contains(@class, "xflagsBig-") or contains(@class, "xflagsSmall-")][1]', $currencyNode)->item(0);
			$countryName = '';
			if ($flagNode instanceof DOMElement) {
				$classes = preg_split('/\s+/', trim((string) $flagNode->getAttribute('class')));
				if (is_array($classes)) {
					foreach ($classes as $classNameRaw) {
						$className = trim((string) $classNameRaw);
						if (str_starts_with($className, 'xflagsBig-')) {
							$countryName = str_replace('-', ' ', substr($className, strlen('xflagsBig-')));
							break;
						}
						if (str_starts_with($className, 'xflagsSmall-')) {
							$countryName = str_replace('-', ' ', substr($className, strlen('xflagsSmall-')));
							break;
						}
					}
				}
			}

			if ($amountText === '' || $currencyCode === '') {
				continue;
			}

			$key = strtolower($currencyCode . '|' . $countryName);
			if (isset($seen[$key])) {
				continue;
			}

			$seen[$key] = true;
			$accounts[] = [
				'amount' => $amountText,
				'currency' => $currencyCode,
				'country' => $countryName,
			];
		}
	}

	if (preg_match('/var\s+citizenAccounts\s*=\s*\[(.*?)\];/is', $html, $accountsMatch) === 1) {
		$accountsBlock = (string) ($accountsMatch[1] ?? '');
		if (preg_match_all('/\{\s*[\'\"]currency[\'\"]\s*:\s*[\'\"]([^\'\"]+)[\'\"]\s*,\s*[\'\"]amount[\'\"]\s*:\s*[\'\"]([^\'\"]+)[\'\"]\s*,\s*[\'\"]country[\'\"]\s*:\s*[\'\"]([^\'\"]*)[\'\"]\s*\}/i', $accountsBlock, $rows, PREG_SET_ORDER)) {
			foreach ($rows as $row) {
				$currencyId = trim((string) ($row[1] ?? ''));
				$amount = trim((string) ($row[2] ?? ''));
				$country = trim((string) ($row[3] ?? ''));
				if ($amount === '') {
					continue;
				}
				$currencyCode = $currencyId === '0' ? 'Gold' : ('ID:' . $currencyId);
				$key = strtolower($currencyCode . '|' . $country);
				if (isset($seen[$key])) {
					continue;
				}

				$seen[$key] = true;
				$accounts[] = [
					'amount' => $amount,
					'currency' => $currencyCode,
					'country' => $country,
				];
			}
		}
	}

	return $accounts;
}

function extractRegisteredEmailFromPersonalDataHtml(string $html): string
{
	if (trim($html) === '') {
		return '';
	}

	$dom = new DOMDocument();
	libxml_use_internal_errors(true);
	$dom->loadHTML($html);
	libxml_clear_errors();
	$xpath = new DOMXPath($dom);

	$emailNode = $xpath->query('//form[contains(@action, "editCitizen") and .//input[@name="action" and @value="CHANGE_EMAIL"]]//input[@name="mail"][1]')->item(0);
	if (!($emailNode instanceof DOMElement)) {
		$emailNode = $xpath->query('//input[@name="mail"][1]')->item(0);
	}
	if (!($emailNode instanceof DOMElement)) {
		$emailNode = $xpath->query('//input[@type="email"][1]')->item(0);
	}

	if (!($emailNode instanceof DOMElement)) {
		return '';
	}

	$email = trim((string) $emailNode->getAttribute('value'));
	if ($email === '') {
		return '';
	}

	return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '';
}

function extractStorageEquipmentInventoryFromHtml(string $html): array
{
	if (trim($html) === '') {
		return ['equipped' => [], 'storage' => []];
	}

	$dom = new DOMDocument();
	libxml_use_internal_errors(true);
	$dom->loadHTML($html);
	libxml_clear_errors();
	$xpath = new DOMXPath($dom);

	$equippedItems = [];
	$storageItems = [];
	$seenEquippedIds = [];
	$seenStorageIds = [];

	$equipmentSlotNodes = $xpath->query('//*[@id="myEquipment"]//div[contains(concat(" ", normalize-space(@class), " "), " equipmentItem ")]');
	if ($equipmentSlotNodes) {
		foreach ($equipmentSlotNodes as $slotNode) {
			if (!($slotNode instanceof DOMElement)) {
				continue;
			}

			$typeNode = $xpath->query('.//div[contains(concat(" ", normalize-space(@class), " "), " equipmentType ")][1]', $slotNode)->item(0);
			$typeHint = $typeNode instanceof DOMElement ? compactNodeText((string) $typeNode->textContent) : '';

			$equippedNode = $xpath->query('.//div[contains(concat(" ", normalize-space(@class), " "), " equipmentInStorage ") and contains(concat(" ", normalize-space(@class), " "), " equippedItem ")][1]', $slotNode)->item(0);
			if (!($equippedNode instanceof DOMElement)) {
				continue;
			}

			$item = parseStorageEquipmentItemNode($xpath, $equippedNode, $typeHint, true);
			$itemId = trim((string) ($item['id'] ?? ''));
			if ($itemId === '' || isset($seenEquippedIds[$itemId])) {
				continue;
			}

			$seenEquippedIds[$itemId] = true;
			$equippedItems[] = $item;
		}
	}

	$storageNodes = $xpath->query('//*[@id="equipmentTable"]//div[contains(concat(" ", normalize-space(@class), " "), " equipmentInStorage ")]');
	if (!($storageNodes instanceof DOMNodeList) || $storageNodes->length === 0) {
		$storageNodes = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " equipmentInStorage ") and .//button[contains(concat(" ", normalize-space(@class), " "), " putItemOnAuction ")]]');
	}
	if (!($storageNodes instanceof DOMNodeList) || $storageNodes->length === 0) {
		$storageNodes = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " equipmentInStorage ")]');
	}
	if ($storageNodes) {
		foreach ($storageNodes as $storageNode) {
			if (!($storageNode instanceof DOMElement)) {
				continue;
			}

			$isEquippedNode = hasClassToken($storageNode, 'equippedItem');
			$item = parseStorageEquipmentItemNode($xpath, $storageNode, '', $isEquippedNode);
			$itemId = trim((string) ($item['id'] ?? ''));
			if ($itemId === '') {
				continue;
			}

			if ($isEquippedNode) {
				if (isset($seenEquippedIds[$itemId])) {
					continue;
				}
				$seenEquippedIds[$itemId] = true;
				$equippedItems[] = $item;
				continue;
			}

			if (isset($seenStorageIds[$itemId])) {
				continue;
			}

			$seenStorageIds[$itemId] = true;
			$storageItems[] = $item;
		}
	}

	return [
		'equipped' => $equippedItems,
		'storage' => $storageItems,
	];
}

function parseStorageEquipmentItemNode(DOMXPath $xpath, DOMElement $itemNode, string $typeHint = '', bool $isEquipped = false): array
{
	$compactText = compactNodeText((string) $itemNode->textContent);
	$detailLinkNode = $xpath->query('.//a[contains(@href, "showEquipment.html")][1]', $itemNode)->item(0);
	$detailUrlRaw = $detailLinkNode instanceof DOMElement ? trim((string) $detailLinkNode->getAttribute('href')) : '';
	$detailUrl = $detailUrlRaw !== '' ? resolveUrl(serverUrl('storage.html?storageType=EQUIPMENT'), $detailUrlRaw) : '';

	$itemId = '';
	if ($detailUrlRaw !== '' && preg_match('/[?&]id=(\d+)/', $detailUrlRaw, $idMatch) === 1) {
		$itemId = trim((string) ($idMatch[1] ?? ''));
	}
	if ($itemId === '') {
		$buttonWithId = $xpath->query('.//button[@data-id][1]', $itemNode)->item(0);
		if ($buttonWithId instanceof DOMElement) {
			$itemId = preg_replace('/\D+/', '', (string) $buttonWithId->getAttribute('data-id'));
		}
	}

	$name = '';
	if (preg_match('/^(.+?)\s*\(#\d+\)/', $compactText, $nameMatch) === 1) {
		$name = trim((string) ($nameMatch[1] ?? ''));
	}

	$qualityLabel = '';
	$typeLabel = trim($typeHint);
	$qualityNode = $xpath->query('.//b[1]', $itemNode)->item(0);
	if ($qualityNode instanceof DOMElement) {
		$qualityText = compactNodeText((string) $qualityNode->textContent);
		if (preg_match('/\b(Q\d+)\b/i', $qualityText, $qualityMatch) === 1) {
			$qualityLabel = strtoupper(trim((string) ($qualityMatch[1] ?? '')));
		}
		if ($typeLabel === '') {
			$typeLabel = trim((string) preg_replace('/\bQ\d+\b\s*/i', '', $qualityText));
		}
	}

	$setLabel = '';
	$setNode = $xpath->query('.//span[1]', $itemNode)->item(0);
	if ($setNode instanceof DOMElement) {
		$setLabel = compactNodeText((string) $setNode->textContent);
	}

	$imageClass = '';
	$imageNode = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " equipmentImage ")][1]', $itemNode)->item(0);
	if ($imageNode instanceof DOMElement) {
		$classes = preg_split('/\s+/', trim((string) $imageNode->getAttribute('class')));
		if (is_array($classes)) {
			foreach ($classes as $classNameRaw) {
				$className = trim((string) $classNameRaw);
				if ($className === '' || in_array($className, ['equipmentImage', 'help', 'class'], true)) {
					continue;
				}
				$imageClass = $className;
				break;
			}
		}
	}

	$qualityClass = '';
	$backNode = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " equipmentBack ")][1]', $itemNode)->item(0);
	if ($backNode instanceof DOMElement) {
		$classes = preg_split('/\s+/', trim((string) $backNode->getAttribute('class')));
		if (is_array($classes)) {
			foreach ($classes as $classNameRaw) {
				$className = trim((string) $classNameRaw);
				if ($className === '' || $className === 'equipmentBack') {
					continue;
				}
				if (preg_match('/^q\d+$/i', $className) === 1) {
					$qualityClass = strtolower($className);
					break;
				}
			}
		}
	}

	$attributes = [];
	$attributeNodes = $xpath->query('.//span[contains(concat(" ", normalize-space(@class), " "), " parameterFont ")]', $itemNode);
	if ($attributeNodes) {
		$seen = [];
		foreach ($attributeNodes as $attributeNode) {
			if (!($attributeNode instanceof DOMElement)) {
				continue;
			}

			$containerNode = $attributeNode->parentNode;
			if ($containerNode instanceof DOMNode) {
				$attributeText = compactNodeText((string) $containerNode->textContent);
			} else {
				$attributeText = compactNodeText((string) $attributeNode->textContent);
			}

			if ($attributeText === '' || isset($seen[$attributeText])) {
				continue;
			}

			$seen[$attributeText] = true;
			$attributes[] = $attributeText;
			if (count($attributes) >= 8) {
				break;
			}
		}
	}

	$auctionButtonNode = $xpath->query('.//button[contains(concat(" ", normalize-space(@class), " "), " putItemOnAuction ")][1]', $itemNode)->item(0);
	$auctionItemId = '';
	$auctionPrice = '';
	$auctionLength = '';
	if ($auctionButtonNode instanceof DOMElement) {
		$auctionItemId = preg_replace('/\D+/', '', (string) $auctionButtonNode->getAttribute('data-id'));
		$auctionPrice = trim((string) $auctionButtonNode->getAttribute('data-price'));
		$auctionLength = trim((string) $auctionButtonNode->getAttribute('data-length'));
	}

	return [
		'id' => $itemId,
		'name' => $name,
		'type' => $typeLabel,
		'quality' => $qualityLabel,
		'set' => $setLabel,
		'imageClass' => $imageClass,
		'qualityClass' => $qualityClass,
		'attributes' => $attributes,
		'detailUrl' => $detailUrl,
		'isEquipped' => $isEquipped,
		'auctionItemId' => $auctionItemId,
		'auctionPrice' => $auctionPrice,
		'auctionLength' => $auctionLength,
		'canAuction' => $auctionItemId !== '' && $auctionPrice !== '' && $auctionLength !== '',
	];
}

function hasClassToken(DOMElement $node, string $className): bool
{
	$classAttr = ' ' . preg_replace('/\s+/', ' ', trim((string) $node->getAttribute('class'))) . ' ';
	return str_contains($classAttr, ' ' . $className . ' ');
}

function buildProductMarketOffersUrl(string $baseUrl, string $quality, string $type, string $countryId = '-1', string $page = ''): string
{
	$params = [
		'quality' => $quality,
		'type' => $type,
		'countryId' => $countryId,
	];

	if ($page !== '' && preg_match('/^\d+$/', $page) === 1 && (int) $page > 0) {
		$params['page'] = $page;
	}

	return $baseUrl . '?' . http_build_query($params);
}

function submitProductMarketBuy($ch, string $refererUrl, array $defaultHeaders, array $offerData): array
{
	$offerId = trim((string) ($offerData['offerId'] ?? ''));
	$quantity = trim((string) ($offerData['quantity'] ?? '1'));
	$currencyId = trim((string) ($offerData['currencyId'] ?? ''));
	$safeReferer = $refererUrl !== '' ? $refererUrl : serverUrl('productMarket.html');

	$result = [
		'attempted' => true,
		'saved' => false,
		'reason' => 'product-market-buy-data-missing',
		'httpStatus' => 0,
		'url' => '',
		'offerId' => $offerId,
		'quantity' => $quantity,
		'currencyId' => $currencyId,
		'responseSnippet' => '',
		'requestPayload' => [],
		'error' => '',
	];

	if (preg_match('/^\d+$/', $offerId) !== 1 || preg_match('/^\d+$/', $quantity) !== 1 || preg_match('/^\d+$/', $currencyId) !== 1) {
		return $result;
	}

	$targetUrls = [
		resolveUrl($safeReferer, 'productMarket.html'),
		resolveUrl($safeReferer, 'productMarketOffers'),
	];
	$payloads = [
		['action' => 'buy', 'id' => $offerId, 'quantity' => $quantity, 'currencyId' => $currencyId],
		['id' => $offerId, 'quantity' => $quantity, 'currencyId' => $currencyId],
		['offerId' => $offerId, 'quantity' => $quantity, 'currencyId' => $currencyId],
		['id' => $offerId, 'amount' => $quantity, 'currencyId' => $currencyId],
		['id' => $offerId, 'quantity' => $quantity, 'currency' => $currencyId],
	];

	$postHeaders = [
		'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
		'Origin: ' . rtrim(serverUrl(''), '/'),
		'Referer: ' . $safeReferer,
		'X-Requested-With: XMLHttpRequest',
	];

	$lastStep = null;
	$lastPayload = [];
	$lastUrl = '';
	foreach ($targetUrls as $targetUrl) {
		foreach ($payloads as $payload) {
			$step = curlRequest($ch, $targetUrl, [
				CURLOPT_POST => true,
				CURLOPT_HTTPGET => false,
				CURLOPT_POSTFIELDS => http_build_query($payload),
				CURLOPT_HTTPHEADER => array_merge($defaultHeaders, $postHeaders),
			]);

			$lastStep = $step;
			$lastPayload = $payload;
			$lastUrl = $targetUrl;

			$body = (string) ($step['body'] ?? '');
			$bodyLower = strtolower($body);
			$httpOk = $step['errno'] === 0 && (int) ($step['statusCode'] ?? 0) >= 200 && (int) ($step['statusCode'] ?? 0) < 400;
			$insufficientFunds = str_contains($bodyLower, 'insufficient funds');
			$hasHardError = preg_match('/\b(error|failed|forbidden|invalid|exception|not enough|insufficient)\b/i', $body) === 1;
			$looksSuccess = preg_match('/\b(success|bought|purchased|done|completed)\b/i', $body) === 1;

			if ($insufficientFunds) {
				return [
					'attempted' => true,
					'saved' => false,
					'reason' => 'insufficient-funds',
					'httpStatus' => (int) ($step['statusCode'] ?? 0),
					'url' => (string) ($step['effectiveUrl'] ?: $targetUrl),
					'offerId' => $offerId,
					'quantity' => $quantity,
					'currencyId' => $currencyId,
					'responseSnippet' => trim(substr(compactNodeText($body), 0, 280)),
					'requestPayload' => $payload,
					'error' => (string) ($step['error'] ?? ''),
				];
			}

			if ($httpOk && ($looksSuccess || !$hasHardError)) {
				return [
					'attempted' => true,
					'saved' => true,
					'reason' => 'product-market-buy-submitted',
					'httpStatus' => (int) ($step['statusCode'] ?? 0),
					'url' => (string) ($step['effectiveUrl'] ?: $targetUrl),
					'offerId' => $offerId,
					'quantity' => $quantity,
					'currencyId' => $currencyId,
					'responseSnippet' => trim(substr(compactNodeText($body), 0, 280)),
					'requestPayload' => $payload,
					'error' => (string) ($step['error'] ?? ''),
				];
			}
		}
	}

	$lastBody = is_array($lastStep) ? (string) ($lastStep['body'] ?? '') : '';
	return [
		'attempted' => true,
		'saved' => false,
		'reason' => is_array($lastStep) && (int) ($lastStep['errno'] ?? 0) !== 0
			? 'product-market-buy-request-error'
			: 'product-market-buy-rejected',
		'httpStatus' => is_array($lastStep) ? (int) ($lastStep['statusCode'] ?? 0) : 0,
		'url' => is_array($lastStep) ? (string) (($lastStep['effectiveUrl'] ?? '') !== '' ? $lastStep['effectiveUrl'] : $lastUrl) : $lastUrl,
		'offerId' => $offerId,
		'quantity' => $quantity,
		'currencyId' => $currencyId,
		'responseSnippet' => trim(substr(compactNodeText($lastBody), 0, 280)),
		'requestPayload' => $lastPayload,
		'error' => is_array($lastStep) ? (string) ($lastStep['error'] ?? '') : '',
	];
}

function detectFreeStarterPackFromHtml(string $html, string $baseUrl): array
{
	$normalizedBaseUrl = $baseUrl !== '' ? $baseUrl : serverUrl('index.html');
	$result = [
		'checked' => false,
		'found' => false,
		'claimButtonFound' => false,
		'source' => '',
		'openUrl' => resolveUrl($normalizedBaseUrl, 'shop.html?shopType=PROMOTIONS'),
		'claimUrl' => '',
		'reason' => 'empty-html',
	];

	if (trim($html) === '') {
		return $result;
	}

	$result['checked'] = true;
	$result['reason'] = 'not-found';

	$dom = new DOMDocument();
	libxml_use_internal_errors(true);
	$dom->loadHTML($html);
	libxml_clear_errors();

	$xpath = new DOMXPath($dom);
	$claimNodeGlobal = $xpath->query('//*[contains(translate(normalize-space(string(.)), "abcdefghijklmnopqrstuvwxyz", "ABCDEFGHIJKLMNOPQRSTUVWXYZ"), "FREE_STARTER_PACK")]//*[self::a or self::button or self::input][contains(translate(normalize-space(string(.)), "abcdefghijklmnopqrstuvwxyz", "ABCDEFGHIJKLMNOPQRSTUVWXYZ"), "CLAIM") or contains(translate(@value, "abcdefghijklmnopqrstuvwxyz", "ABCDEFGHIJKLMNOPQRSTUVWXYZ"), "CLAIM")][1]')->item(0);
	if ($claimNodeGlobal instanceof DOMElement) {
		$result['claimButtonFound'] = true;
		$claimUrlDetected = detectActionUrlFromElement($xpath, $claimNodeGlobal, $normalizedBaseUrl);
		if ($claimUrlDetected !== '') {
			$result['claimUrl'] = $claimUrlDetected;
		}
	}

	$containerNode = $xpath->query('//*[@id="FREE_STARTER_PACKContainer"]')->item(0);
	if ($containerNode instanceof DOMElement) {
		$result['found'] = true;
		$result['source'] = 'container';
		$result['reason'] = 'container-detected';

		$claimNode = $xpath->query('.//*[self::a or self::button or self::input][contains(translate(normalize-space(string(.)), "abcdefghijklmnopqrstuvwxyz", "ABCDEFGHIJKLMNOPQRSTUVWXYZ"), "CLAIM") or contains(translate(@value, "abcdefghijklmnopqrstuvwxyz", "ABCDEFGHIJKLMNOPQRSTUVWXYZ"), "CLAIM")][1]', $containerNode)->item(0);
		if ($claimNode instanceof DOMElement) {
			$result['claimButtonFound'] = true;
			$claimUrl = detectActionUrlFromElement($xpath, $claimNode, $normalizedBaseUrl);
			if ($claimUrl !== '') {
				$result['claimUrl'] = $claimUrl;
			}
		}
	}

	if (!$result['found']) {
		$promotionNode = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " personalizedPromotions ") and contains(translate(normalize-space(string(.)), "abcdefghijklmnopqrstuvwxyz", "ABCDEFGHIJKLMNOPQRSTUVWXYZ"), "FREE_STARTER_PACK")][1]')->item(0);
		if ($promotionNode instanceof DOMElement) {
			$result['found'] = true;
			$result['source'] = 'personalized-promotions';
			$result['reason'] = 'personalized-promotions-detected';
		}
	}

	if (!$result['found']) {
		$alertNode = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " alert ") and contains(translate(normalize-space(string(.)), "abcdefghijklmnopqrstuvwxyz", "ABCDEFGHIJKLMNOPQRSTUVWXYZ"), "FREE_STARTER_PACK")][1]')->item(0);
		if ($alertNode instanceof DOMElement) {
			$result['found'] = true;
			$result['source'] = 'alert';
			$result['reason'] = 'notification-alert-detected';
		}
	}

	if (!$result['found'] && stripos($html, 'FREE_STARTER_PACK') !== false) {
		$result['found'] = true;
		$result['source'] = 'html-keyword';
		$result['reason'] = 'keyword-detected';
	}

	if (!$result['found'] && $result['claimButtonFound']) {
		$result['found'] = true;
		$result['source'] = 'claim-button';
		$result['reason'] = 'claim-button-detected';
	}

	if ($result['claimUrl'] !== '') {
		$result['openUrl'] = $result['claimUrl'];
	}

	return $result;
}

function extractProductMarketOffersFromHtml(string $html, string $baseUrl): array
{
	if (trim($html) === '') {
		return [];
	}

	$dom = new DOMDocument();
	libxml_use_internal_errors(true);
	$dom->loadHTML($html);
	libxml_clear_errors();
	$xpath = new DOMXPath($dom);

	$items = [];
	$seen = [];

	$cardNodes = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " productMarketOffer ")]');
	if ($cardNodes && $cardNodes->length > 0) {
		foreach ($cardNodes as $cardNode) {
			if (!($cardNode instanceof DOMElement)) {
				continue;
			}

			$buttonNode = $xpath->query('.//button[contains(concat(" ", normalize-space(@class), " "), " btn-buy ")][1]', $cardNode)->item(0);
			$offerId = $buttonNode instanceof DOMElement ? trim((string) $buttonNode->getAttribute('data-id')) : '';
			$product = $buttonNode instanceof DOMElement ? compactNodeText((string) $buttonNode->getAttribute('data-product')) : '';
			$quality = $buttonNode instanceof DOMElement ? compactNodeText((string) $buttonNode->getAttribute('data-quality')) : '';
			$seller = $buttonNode instanceof DOMElement ? compactNodeText((string) $buttonNode->getAttribute('data-seller')) : '';
			$countryName = $buttonNode instanceof DOMElement ? compactNodeText((string) $buttonNode->getAttribute('data-country-name')) : '';
			$currency = $buttonNode instanceof DOMElement ? compactNodeText((string) $buttonNode->getAttribute('data-currency')) : '';
			$currencyId = $buttonNode instanceof DOMElement ? trim((string) $buttonNode->getAttribute('data-currency-id')) : '';
			$priceLocal = $buttonNode instanceof DOMElement ? compactNodeText((string) $buttonNode->getAttribute('data-price')) : '';
			$priceGold = $buttonNode instanceof DOMElement ? compactNodeText((string) $buttonNode->getAttribute('data-price-gold')) : '';
			$maxQuantity = $buttonNode instanceof DOMElement ? trim((string) $buttonNode->getAttribute('data-max')) : '';
			$dataQuantity = $buttonNode instanceof DOMElement ? trim((string) $buttonNode->getAttribute('data-quantity')) : '';

			if ($seller === '') {
				$sellerNode = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " citizenName ")][1]', $cardNode)->item(0);
				if ($sellerNode instanceof DOMElement) {
					$seller = compactNodeText((string) $sellerNode->textContent);
				}
			}

			$quantityText = $dataQuantity;
			if ($quantityText === '') {
				$quantityNode = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " quantity ")]//span[1]', $cardNode)->item(0);
				if ($quantityNode instanceof DOMElement) {
					$quantityText = compactNodeText((string) $quantityNode->textContent);
				}
			}

			$hasBuyButton = $buttonNode instanceof DOMElement && $offerId !== '';
			$buyStatus = '';
			if (!$hasBuyButton) {
				$buyStatusNode = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " buy ")]//*[self::strong or self::span][normalize-space(string(.))!=""][1]', $cardNode)->item(0);
				if ($buyStatusNode instanceof DOMElement) {
					$buyStatus = compactNodeText((string) $buyStatusNode->textContent);
				}
			}

			if ($priceLocal === '' || $currency === '') {
				$priceBlock = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " price ")]//div[1]', $cardNode)->item(0);
				if ($priceBlock instanceof DOMElement) {
					$priceValueNode = $xpath->query('.//b[1]', $priceBlock)->item(0);
					$priceValue = $priceValueNode instanceof DOMElement
						? trim((string) ($priceValueNode->getAttribute('title') !== '' ? $priceValueNode->getAttribute('title') : $priceValueNode->textContent))
						: '';
					$priceText = compactNodeText((string) $priceBlock->textContent);
					if ($currency === '' && preg_match('/\b([A-Z]{2,6})\b/', $priceText, $currencyMatch) === 1) {
						$currency = (string) ($currencyMatch[1] ?? '');
					}
					if ($priceLocal === '' && $priceValue !== '') {
						$priceLocal = $priceValue;
					}
				}
			}

			if ($priceGold === '') {
				$goldNode = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " price ")]//div[contains(translate(normalize-space(string(.)), "abcdefghijklmnopqrstuvwxyz", "ABCDEFGHIJKLMNOPQRSTUVWXYZ"), "GOLD")][1]//b[1]', $cardNode)->item(0);
				if ($goldNode instanceof DOMElement) {
					$priceGold = trim((string) ($goldNode->getAttribute('title') !== '' ? $goldNode->getAttribute('title') : $goldNode->textContent));
				}
			}

			$titleParts = [];
			if ($product !== '') {
				$titleParts[] = $product;
			}
			if ($quality !== '') {
				$titleParts[] = 'Q' . $quality;
			}
			$title = trim(implode(' ', $titleParts));
			if ($title === '') {
				$title = 'Oferta de mercado';
			}

			$key = md5($offerId . '|' . $title . '|' . $seller . '|' . $quantityText . '|' . $priceLocal . '|' . $currency);
			if (isset($seen[$key])) {
				continue;
			}

			$seen[$key] = true;
			$priceDisplay = '';
			if ($priceLocal !== '' && $currency !== '') {
				$priceDisplay = $priceLocal . ' ' . $currency;
			} elseif ($priceLocal !== '') {
				$priceDisplay = $priceLocal;
			}

			$items[] = [
				'title' => $title,
				'price' => $priceDisplay,
				'priceGold' => $priceGold !== '' ? $priceGold . ' Gold' : '',
				'seller' => $seller,
				'company' => '',
				'country' => $countryName,
				'quantity' => $quantityText,
				'maxQuantity' => $maxQuantity !== '' ? $maxQuantity : $quantityText,
				'offerId' => $offerId,
				'currencyId' => $currencyId,
				'canBuy' => $hasBuyButton,
				'buyStatus' => $buyStatus,
				'url' => '',
				'rawText' => compactNodeText((string) $cardNode->textContent),
			];

			if (count($items) >= 100) {
				break;
			}
		}

		if (!empty($items)) {
			return $items;
		}
	}

	$rowNodes = $xpath->query('//tr[.//a[contains(@href,"company.html") or contains(@href,"profile.html") or contains(@href,"productMarket")]]');
	if (!$rowNodes || $rowNodes->length === 0) {
		$rowNodes = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " offer ") or contains(concat(" ", normalize-space(@class), " "), " marketOffer ")]');
	}

	if (!$rowNodes) {
		return [];
	}

	foreach ($rowNodes as $rowNode) {
		if (!($rowNode instanceof DOMElement)) {
			continue;
		}

		$rowText = compactNodeText((string) $rowNode->textContent);
		if ($rowText === '' || strlen($rowText) < 10) {
			continue;
		}

		$normalized = strtolower($rowText);
		if (str_contains($normalized, 'price') && str_contains($normalized, 'seller')) {
			continue;
		}

		$productTitle = '';
		$productImageNode = $xpath->query('.//img[@title][1]', $rowNode)->item(0);
		if ($productImageNode instanceof DOMElement) {
			$productTitle = compactNodeText((string) $productImageNode->getAttribute('title'));
		}

		$profileNode = $xpath->query('.//a[contains(@href,"profile.html")][1]', $rowNode)->item(0);
		$seller = $profileNode instanceof DOMElement ? compactNodeText((string) $profileNode->textContent) : '';

		$companyNode = $xpath->query('.//a[contains(@href,"company.html")][1]', $rowNode)->item(0);
		$company = $companyNode instanceof DOMElement ? compactNodeText((string) $companyNode->textContent) : '';

		$price = '';
		if (preg_match('/([0-9]+(?:[\.,][0-9]+)?)\s*([A-Z]{2,6}|Gold|gold)/', $rowText, $priceMatch) === 1) {
			$price = compactNodeText((string) ($priceMatch[1] . ' ' . $priceMatch[2]));
		}

		$mainLinkUrl = '';
		$linkNode = $xpath->query('.//a[@href][1]', $rowNode)->item(0);
		if ($linkNode instanceof DOMElement) {
			$href = trim((string) $linkNode->getAttribute('href'));
			if ($href !== '' && $href !== '#') {
				$mainLinkUrl = resolveUrl($baseUrl !== '' ? $baseUrl : serverUrl('productMarket.html'), $href);
			}
		}

		$display = $productTitle !== '' ? $productTitle : $rowText;
		$key = md5($display . '|' . $seller . '|' . $company . '|' . $price);
		if (isset($seen[$key])) {
			continue;
		}

		$seen[$key] = true;
		$items[] = [
			'title' => $display,
			'price' => $price,
			'seller' => $seller,
			'company' => $company,
			'url' => $mainLinkUrl,
			'rawText' => $rowText,
		];

		if (count($items) >= 60) {
			break;
		}
	}

	return $items;
}

function enrichBattlesWithCombatState($ch, array $defaultHeaders, array $battles): array
{
	$enriched = [];
	$maxDetails = 12;
	foreach ($battles as $index => $battle) {
		$item = is_array($battle) ? $battle : [];
		$url = trim((string) ($item['url'] ?? ''));
		if ($url === '' || $index >= $maxDetails) {
			$item['detailsLoaded'] = false;
			$item['canFight'] = false;
			$item['canFightDefender'] = false;
			$item['canFightAttacker'] = false;
			$item['canChangeSide'] = false;
			$item['fightFor'] = '';
			$item['countryA'] = '';
			$item['countryB'] = '';
			$item['battleType'] = 'unknown';
			$item['battleTypeLabel'] = 'Tipo desconocido';
			$item['battleRegionId'] = '';
			$item['travelDefenderRegionId'] = '';
			$item['travelDefenderRegionName'] = '';
			$item['travelDefenderRegionUrl'] = '';
			$item['travelDefenderActionUrl'] = '';
			$item['travelDefenderCountryId'] = '';
			$item['travelDefenderRedirectUrl'] = '';
			$item['travelDefenderTicketOptions'] = [];
			$item['travelAttackerRegionId'] = '';
			$item['travelAttackerRegionName'] = '';
			$item['travelAttackerRegionUrl'] = '';
			$item['travelAttackerActionUrl'] = '';
			$item['travelAttackerCountryId'] = '';
			$item['travelAttackerRedirectUrl'] = '';
			$item['travelAttackerTicketOptions'] = [];
			$item['weaponQ1'] = '';
			$item['weaponQ5'] = '';
			$item['fightActionUrl'] = '';
			$item['changeSideUrl'] = '';
			$item['detailReason'] = $url === '' ? 'battle-url-missing' : 'battle-detail-skip-limit';
			$enriched[] = $item;
			continue;
		}

		$detail = inspectBattleCombatState($ch, $defaultHeaders, $url);
		$item['detailsLoaded'] = true;
		$item['canFight'] = (bool) ($detail['canFight'] ?? false);
		$item['canFightDefender'] = (bool) ($detail['canFightDefender'] ?? false);
		$item['canFightAttacker'] = (bool) ($detail['canFightAttacker'] ?? false);
		$item['canChangeSide'] = (bool) ($detail['canChangeSide'] ?? false);
		$item['fightFor'] = (string) ($detail['fightFor'] ?? '');
		$item['countryA'] = (string) ($detail['countryA'] ?? '');
		$item['countryB'] = (string) ($detail['countryB'] ?? '');
		$item['battleType'] = (string) ($detail['battleType'] ?? 'unknown');
		$item['battleTypeLabel'] = (string) ($detail['battleTypeLabel'] ?? 'Tipo desconocido');
		$item['battleRegionId'] = (string) ($detail['battleRegionId'] ?? '');
		$item['travelDefenderRegionId'] = (string) ($detail['travelDefenderRegionId'] ?? '');
		$item['travelDefenderRegionName'] = (string) ($detail['travelDefenderRegionName'] ?? '');
		$item['travelDefenderRegionUrl'] = (string) ($detail['travelDefenderRegionUrl'] ?? '');
		$item['travelDefenderActionUrl'] = (string) ($detail['travelDefenderActionUrl'] ?? '');
		$item['travelDefenderCountryId'] = (string) ($detail['travelDefenderCountryId'] ?? '');
		$item['travelDefenderRedirectUrl'] = (string) ($detail['travelDefenderRedirectUrl'] ?? '');
		$item['travelDefenderTicketOptions'] = is_array($detail['travelDefenderTicketOptions'] ?? null) ? $detail['travelDefenderTicketOptions'] : [];
		$item['travelAttackerRegionId'] = (string) ($detail['travelAttackerRegionId'] ?? '');
		$item['travelAttackerRegionName'] = (string) ($detail['travelAttackerRegionName'] ?? '');
		$item['travelAttackerRegionUrl'] = (string) ($detail['travelAttackerRegionUrl'] ?? '');
		$item['travelAttackerActionUrl'] = (string) ($detail['travelAttackerActionUrl'] ?? '');
		$item['travelAttackerCountryId'] = (string) ($detail['travelAttackerCountryId'] ?? '');
		$item['travelAttackerRedirectUrl'] = (string) ($detail['travelAttackerRedirectUrl'] ?? '');
		$item['travelAttackerTicketOptions'] = is_array($detail['travelAttackerTicketOptions'] ?? null) ? $detail['travelAttackerTicketOptions'] : [];
		$item['playerSide'] = (string) ($detail['playerSide'] ?? '');
		$item['enemyCountry'] = (string) ($detail['enemyCountry'] ?? '');
		$item['fightRoundId'] = (string) ($detail['fightRoundId'] ?? '');
		$item['fightRequestUrl'] = (string) ($detail['fightRequestUrl'] ?? '');
		$item['fightIp'] = (string) ($detail['fightIp'] ?? '');
		$item['fightServerName'] = (string) ($detail['fightServerName'] ?? '');
		$item['fightCitizenId'] = (string) ($detail['fightCitizenId'] ?? '');
		$item['fightMyCitizenship'] = (string) ($detail['fightMyCitizenship'] ?? '');
		$item['fightCitizenRegion'] = (string) ($detail['fightCitizenRegion'] ?? '');
		$item['fightSecurityHash'] = (string) ($detail['fightSecurityHash'] ?? '');
		$item['fightMousePattern'] = (string) ($detail['fightMousePattern'] ?? '');
		$item['fightGameDay'] = (string) ($detail['fightGameDay'] ?? '');
		$item['weaponQ1'] = (string) ($detail['weaponQ1'] ?? '');
		$item['weaponQ5'] = (string) ($detail['weaponQ5'] ?? '');
		$item['fightActionUrl'] = (string) ($detail['fightActionUrl'] ?? '');
		$item['changeSideUrl'] = (string) ($detail['changeSideUrl'] ?? '');
		$item['detailHttpStatus'] = (int) ($detail['httpStatus'] ?? 0);
		$item['detailReason'] = (string) ($detail['reason'] ?? 'unknown');
		$enriched[] = $item;
	}

	return $enriched;
}

function inspectBattleCombatState($ch, array $defaultHeaders, string $battleUrl): array
{
	$step = curlRequest($ch, $battleUrl, [
		CURLOPT_POST => false,
		CURLOPT_HTTPGET => true,
		CURLOPT_HTTPHEADER => $defaultHeaders,
	]);

	$html = (string) ($step['body'] ?? '');
	$ok = $step['errno'] === 0
		&& (int) ($step['statusCode'] ?? 0) >= 200
		&& (int) ($step['statusCode'] ?? 0) < 400;
	if (!$ok || trim($html) === '') {
		return [
			'canFight' => false,
			'canFightDefender' => false,
			'canFightAttacker' => false,
			'canChangeSide' => false,
			'fightFor' => '',
			'countryA' => '',
			'countryB' => '',
			'battleType' => 'unknown',
			'battleTypeLabel' => 'Tipo desconocido',
			'battleRegionId' => '',
			'travelDefenderRegionId' => '',
			'travelDefenderRegionName' => '',
			'travelDefenderRegionUrl' => '',
			'travelDefenderActionUrl' => '',
			'travelDefenderCountryId' => '',
			'travelDefenderRedirectUrl' => '',
			'travelDefenderTicketOptions' => [],
			'travelAttackerRegionId' => '',
			'travelAttackerRegionName' => '',
			'travelAttackerRegionUrl' => '',
			'travelAttackerActionUrl' => '',
			'travelAttackerCountryId' => '',
			'travelAttackerRedirectUrl' => '',
			'travelAttackerTicketOptions' => [],
			'weaponQ1' => '',
			'weaponQ5' => '',
			'fightActionUrl' => '',
			'changeSideUrl' => '',
			'httpStatus' => (int) ($step['statusCode'] ?? 0),
			'reason' => $step['errno'] !== 0 ? 'battle-detail-request-error' : 'battle-detail-http-error',
		];
	}

	$dom = new DOMDocument();
	libxml_use_internal_errors(true);
	$dom->loadHTML($html);
	libxml_clear_errors();
	$xpath = new DOMXPath($dom);

	$fightNode = $xpath->query('//*[@id="fightButton1"]')->item(0);
	$changeSideNode = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " changeSide ")]')->item(0);
	$fightForNode = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " fightFor ")]')->item(0);
	$leftFightNode = $xpath->query('//*[@id="fightButtonLeftSide"]')->item(0);
	$rightFightNode = $xpath->query('//*[@id="fightButtonRightSide"]')->item(0);

	$fightActionUrl = '';
	if ($fightNode instanceof DOMElement) {
		$fightActionUrl = detectActionUrlFromElement($xpath, $fightNode, $battleUrl);
	}

	$changeSideUrl = '';
	if ($changeSideNode instanceof DOMElement) {
		$changeSideUrl = detectActionUrlFromElement($xpath, $changeSideNode, $battleUrl);
	}

	$fightFor = '';
	if ($fightForNode instanceof DOMElement) {
		$fightFor = trim((string) preg_replace('/\s+/', ' ', (string) $fightForNode->textContent));
	}

	$countries = extractBattleCountries($xpath, $html);
	$playerSide = detectBattlePlayerSide($xpath, $leftFightNode, $rightFightNode);
	$enemyCountry = '';
	if ($playerSide === 'defender') {
		$enemyCountry = (string) ($countries['countryB'] ?? '');
	} elseif ($playerSide === 'attacker') {
		$enemyCountry = (string) ($countries['countryA'] ?? '');
	}

	$fightRequestUrl = extractFightRequestUrl($html, $battleUrl);
	$fightRoundId = extractBattleRoundId($xpath);
	$battleRegionInfo = extractBattleRegionFromBattlePage($xpath, $battleUrl);
	$battleRegionId = (string) ($battleRegionInfo['regionId'] ?? '');
	$travelHints = suggestTravelRegionsForBattle(
		$ch,
		$defaultHeaders,
		$battleUrl,
		$battleRegionId,
		(string) ($battleRegionInfo['regionName'] ?? ''),
		(string) ($battleRegionInfo['regionUrl'] ?? ''),
		(string) ($countries['countryA'] ?? ''),
		(string) ($countries['countryB'] ?? '')
	);
	$battleType = [
		'type' => (string) ($travelHints['battleType'] ?? ''),
		'label' => (string) ($travelHints['battleTypeLabel'] ?? ''),
	];
	if ($battleType['type'] === '' || $battleType['label'] === '' || $battleType['type'] === 'unknown') {
		$battleType = detectBattleType($html);
	}
	if ($battleType['type'] === '' || $battleType['type'] === 'unknown') {
		$battleType = [
			'type' => 'direct-attack',
			'label' => 'Normal Battle',
		];
	}
	$fightIp = extractFightRequestStaticValue($html, 'ip');
	$fightServerName = extractFightRequestStaticValue($html, 'serverName');
	$fightCitizenId = extractFightRequestStaticValue($html, 'citizenId');
	$fightMyCitizenship = extractFightRequestStaticValue($html, 'myCitizenship');
	$fightCitizenRegion = extractFightRequestStaticValue($html, 'citizenRegion');
	$fightSecurityHash = extractFightRequestStaticValue($html, 'securityHash');
	$fightMousePattern = extractFightRequestStaticValue($html, 'mousePattern');
	$fightGameDay = extractFightRequestStaticValue($html, 'gameDay');
	$weaponQ1 = extractWeaponQuantityFromBattleHtml($xpath, '1');
	$weaponQ5 = extractWeaponQuantityFromBattleHtml($xpath, '5');

	$canFight = ($leftFightNode instanceof DOMElement && isElementVisible($leftFightNode))
		|| ($rightFightNode instanceof DOMElement && isElementVisible($rightFightNode));
	$canFightDefender = $leftFightNode instanceof DOMElement && isElementVisible($leftFightNode);
	$canFightAttacker = $rightFightNode instanceof DOMElement && isElementVisible($rightFightNode);
	if (!$canFight) {
		$canFight = $fightNode instanceof DOMElement;
	}
	$canChangeSide = $changeSideNode instanceof DOMElement && isElementVisible($changeSideNode);

	// If side-switch is available, player can choose and hit on both sides.
	if ($canChangeSide && $canFight) {
		$canFightDefender = true;
		$canFightAttacker = true;
	}

	// Fallback when only a generic fight button is available: allow current selected side only.
	if (!$canFightDefender && !$canFightAttacker && $canFight) {
		if ($playerSide === 'defender') {
			$canFightDefender = true;
		} elseif ($playerSide === 'attacker') {
			$canFightAttacker = true;
		}
	}

	return [
		'canFight' => $canFight,
		'canFightDefender' => $canFightDefender,
		'canFightAttacker' => $canFightAttacker,
		'canChangeSide' => $canChangeSide,
		'fightFor' => $fightFor,
		'countryA' => (string) ($countries['countryA'] ?? ''),
		'countryB' => (string) ($countries['countryB'] ?? ''),
		'battleType' => (string) ($battleType['type'] ?? 'unknown'),
		'battleTypeLabel' => (string) ($battleType['label'] ?? 'Tipo desconocido'),
		'battleRegionId' => $battleRegionId,
		'travelDefenderRegionId' => (string) ($travelHints['defenderRegionId'] ?? ''),
		'travelDefenderRegionName' => (string) ($travelHints['defenderRegionName'] ?? ''),
		'travelDefenderRegionUrl' => (string) ($travelHints['defenderRegionUrl'] ?? ''),
		'travelDefenderActionUrl' => (string) ($travelHints['defenderTravelActionUrl'] ?? ''),
		'travelDefenderCountryId' => (string) ($travelHints['defenderTravelCountryId'] ?? ''),
		'travelDefenderRedirectUrl' => (string) ($travelHints['defenderTravelRedirectUrl'] ?? ''),
		'travelDefenderTicketOptions' => is_array($travelHints['defenderTravelTicketOptions'] ?? null) ? $travelHints['defenderTravelTicketOptions'] : [],
		'travelAttackerRegionId' => (string) ($travelHints['attackerRegionId'] ?? ''),
		'travelAttackerRegionName' => (string) ($travelHints['attackerRegionName'] ?? ''),
		'travelAttackerRegionUrl' => (string) ($travelHints['attackerRegionUrl'] ?? ''),
		'travelAttackerActionUrl' => (string) ($travelHints['attackerTravelActionUrl'] ?? ''),
		'travelAttackerCountryId' => (string) ($travelHints['attackerTravelCountryId'] ?? ''),
		'travelAttackerRedirectUrl' => (string) ($travelHints['attackerTravelRedirectUrl'] ?? ''),
		'travelAttackerTicketOptions' => is_array($travelHints['attackerTravelTicketOptions'] ?? null) ? $travelHints['attackerTravelTicketOptions'] : [],
		'playerSide' => $playerSide,
		'enemyCountry' => $enemyCountry,
		'fightRoundId' => $fightRoundId,
		'fightRequestUrl' => $fightRequestUrl,
		'fightIp' => $fightIp,
		'fightServerName' => $fightServerName,
		'fightCitizenId' => $fightCitizenId,
		'fightMyCitizenship' => $fightMyCitizenship,
		'fightCitizenRegion' => $fightCitizenRegion,
		'fightSecurityHash' => $fightSecurityHash,
		'fightMousePattern' => $fightMousePattern,
		'fightGameDay' => $fightGameDay,
		'weaponQ1' => $weaponQ1,
		'weaponQ5' => $weaponQ5,
		'fightActionUrl' => $fightActionUrl,
		'changeSideUrl' => $changeSideUrl,
		'httpStatus' => (int) ($step['statusCode'] ?? 0),
		'reason' => 'battle-detail-loaded',
	];
}

function detectBattleType(string $html): array
{
	$dom = new DOMDocument();
	libxml_use_internal_errors(true);
	$dom->loadHTML($html);
	libxml_clear_errors();
	$xpath = new DOMXPath($dom);

	$typeNode = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " battleLocation ")]//span[normalize-space(text())!=""][1]')->item(0);
	if ($typeNode instanceof DOMElement) {
		$typeText = compactNodeText((string) $typeNode->textContent);
		return classifyBattleTypeFromText($typeText);
	}

	return [
		'type' => 'unknown',
		'label' => 'Tipo desconocido',
	];
}

function extractRegionIdFromUrl(string $url): string
{
	$parts = parse_url($url);
	if (!is_array($parts)) {
		return '';
	}

	$query = (string) ($parts['query'] ?? '');
	if ($query === '') {
		return '';
	}

	$params = [];
	parse_str($query, $params);
	$id = isset($params['id']) ? trim((string) $params['id']) : '';
	if ($id !== '' && preg_match('/^\d+$/', $id)) {
		return $id;
	}

	return '';
}

function extractBattleRegionFromBattlePage(DOMXPath $xpath, string $battleUrl): array
{
	$battleRegionLink = $xpath->query('//a[contains(concat(" ", normalize-space(@class), " "), " battleName ") and contains(@href,"region.html?id=")]')->item(0);
	if (!($battleRegionLink instanceof DOMElement)) {
		$battleRegionLink = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " battleLocation ")]//a[contains(@href,"region.html?id=")]')->item(0);
	}
	if (!($battleRegionLink instanceof DOMElement)) {
		$battleRegionLink = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " battleHeader ")]//a[contains(@href,"region.html?id=")]')->item(0);
	}

	if (!($battleRegionLink instanceof DOMElement)) {
		return [
			'regionId' => '',
			'regionName' => '',
			'regionUrl' => '',
		];
	}

	$href = trim((string) $battleRegionLink->getAttribute('href'));
	$regionUrl = resolveUrl($battleUrl, $href);
	$regionId = extractRegionIdFromUrl($regionUrl);
	$regionName = compactNodeText((string) $battleRegionLink->textContent);
	if ($regionName === '' && $regionId !== '') {
		$regionName = 'Region ' . $regionId;
	}

	return [
		'regionId' => $regionId,
		'regionName' => $regionName,
		'regionUrl' => $regionUrl,
	];
}

function suggestTravelRegionsForBattle(
	$ch,
	array $defaultHeaders,
	string $battleUrl,
	string $battleRegionId,
	string $battleRegionName,
	string $battleRegionUrl,
	string $defenderCountry,
	string $attackerCountry
): array
{
	$defenderRegionUrl = '';
	$defenderRegionName = '';
	$defenderTravelForm = emptyTravelFormData();
	$attackerRegionUrl = '';
	$attackerRegionName = '';
	$attackerRegionId = '';
	$attackerTravelForm = emptyTravelFormData();
	$battleType = 'unknown';
	$battleTypeLabel = 'Tipo desconocido';
	static $regionTravelCache = [];

	if ($battleRegionId !== '') {
		$defenderRegionUrl = $battleRegionUrl !== '' ? $battleRegionUrl : resolveUrl($battleUrl, 'region.html?id=' . $battleRegionId);
		$defenderRegionName = $battleRegionName !== '' ? $battleRegionName : ('Region ' . $battleRegionId);
		$regionUrl = $defenderRegionUrl !== '' ? $defenderRegionUrl : resolveUrl($battleUrl, 'region.html?id=' . $battleRegionId);
		$cacheKey = md5($regionUrl);
		if (!isset($regionTravelCache[$cacheKey])) {
			$regionStep = curlRequest($ch, $regionUrl, [
				CURLOPT_POST => false,
				CURLOPT_HTTPGET => true,
				CURLOPT_HTTPHEADER => $defaultHeaders,
			]);
			$regionHtmlCached = (string) ($regionStep['body'] ?? '');
			$regionOkCached = $regionStep['errno'] === 0
				&& (int) $regionStep['statusCode'] >= 200
				&& (int) $regionStep['statusCode'] < 400
				&& trim($regionHtmlCached) !== '';
			$effectiveUrlCached = (string) ($regionStep['effectiveUrl'] ?: $regionUrl);
			$regionTravelCache[$cacheKey] = [
				'ok' => $regionOkCached,
				'html' => $regionHtmlCached,
				'effectiveUrl' => $effectiveUrlCached,
				'travelForm' => $regionOkCached ? extractTravelFormDataFromRegionHtml($regionHtmlCached, $effectiveUrlCached) : emptyTravelFormData(),
			];
		}

		$regionCached = is_array($regionTravelCache[$cacheKey]) ? $regionTravelCache[$cacheKey] : [];
		$regionHtml = (string) ($regionCached['html'] ?? '');
		$regionOk = !empty($regionCached['ok']);
		$regionEffectiveUrl = (string) (($regionCached['effectiveUrl'] ?? '') !== '' ? $regionCached['effectiveUrl'] : $regionUrl);
		$defenderTravelForm = is_array($regionCached['travelForm'] ?? null) ? $regionCached['travelForm'] : emptyTravelFormData();

		if ($regionOk && trim($regionHtml) !== '') {
			$detectedType = detectBattleTypeFromRegionHtml($regionHtml, $battleUrl);
			$battleType = (string) ($detectedType['type'] ?? 'unknown');
			$battleTypeLabel = (string) ($detectedType['label'] ?? 'Tipo desconocido');

			if ($attackerCountry !== '') {
				$match = findNeighborRegionControlledByCountry($regionHtml, $regionEffectiveUrl, $battleRegionId, $attackerCountry);
				$attackerRegionId = (string) ($match['regionId'] ?? '');
				$attackerRegionUrl = (string) ($match['regionUrl'] ?? '');
				$attackerRegionName = (string) ($match['regionName'] ?? '');
				if ($attackerRegionUrl !== '') {
					$attackerTravelForm = fetchRegionTravelFormData($ch, $defaultHeaders, $attackerRegionUrl, $regionTravelCache);
				}
			}
		}
	}

	if ($attackerRegionName === '' && $attackerRegionId !== '') {
		$attackerRegionName = 'Region ' . $attackerRegionId;
	}

	$defenderTargetRegionId = $battleRegionId !== ''
		? $battleRegionId
		: (string) ($defenderTravelForm['regionId'] ?? '');
	$attackerTargetRegionId = $attackerRegionId !== ''
		? $attackerRegionId
		: (string) ($attackerTravelForm['regionId'] ?? '');

	return [
		'defenderRegionId' => $defenderTargetRegionId,
		'defenderRegionName' => $defenderRegionName,
		'defenderRegionUrl' => $defenderRegionUrl,
		'defenderTravelActionUrl' => (string) ($defenderTravelForm['actionUrl'] ?? ''),
		'defenderTravelCountryId' => (string) ($defenderTravelForm['countryId'] ?? ''),
		'defenderTravelRedirectUrl' => (string) ($defenderTravelForm['redirectUrl'] ?? ''),
		'defenderTravelTicketOptions' => is_array($defenderTravelForm['ticketOptions'] ?? null) ? $defenderTravelForm['ticketOptions'] : [],
		'attackerRegionId' => $attackerTargetRegionId,
		'attackerRegionName' => $attackerRegionName,
		'attackerRegionUrl' => $attackerRegionUrl,
		'attackerTravelActionUrl' => (string) ($attackerTravelForm['actionUrl'] ?? ''),
		'attackerTravelCountryId' => (string) ($attackerTravelForm['countryId'] ?? ''),
		'attackerTravelRedirectUrl' => (string) ($attackerTravelForm['redirectUrl'] ?? ''),
		'attackerTravelTicketOptions' => is_array($attackerTravelForm['ticketOptions'] ?? null) ? $attackerTravelForm['ticketOptions'] : [],
		'battleType' => $battleType,
		'battleTypeLabel' => $battleTypeLabel,
	];
}

function emptyTravelFormData(): array
{
	return [
		'actionUrl' => '',
		'countryId' => '',
		'regionId' => '',
		'redirectUrl' => '',
		'ticketOptions' => [],
	];
}

function fetchRegionTravelFormData($ch, array $defaultHeaders, string $regionUrl, array &$cache): array
{
	if ($regionUrl === '') {
		return emptyTravelFormData();
	}

	$cacheKey = md5($regionUrl);
	if (isset($cache[$cacheKey]) && is_array($cache[$cacheKey])) {
		$cachedData = $cache[$cacheKey];
		return is_array($cachedData['travelForm'] ?? null) ? $cachedData['travelForm'] : emptyTravelFormData();
	}

	$regionStep = curlRequest($ch, $regionUrl, [
		CURLOPT_POST => false,
		CURLOPT_HTTPGET => true,
		CURLOPT_HTTPHEADER => $defaultHeaders,
	]);
	$regionHtml = (string) ($regionStep['body'] ?? '');
	$regionOk = $regionStep['errno'] === 0
		&& (int) $regionStep['statusCode'] >= 200
		&& (int) $regionStep['statusCode'] < 400
		&& trim($regionHtml) !== '';
	$effectiveUrl = (string) ($regionStep['effectiveUrl'] ?: $regionUrl);
	$travelForm = $regionOk ? extractTravelFormDataFromRegionHtml($regionHtml, $effectiveUrl) : emptyTravelFormData();

	$cache[$cacheKey] = [
		'ok' => $regionOk,
		'html' => $regionHtml,
		'effectiveUrl' => $effectiveUrl,
		'travelForm' => $travelForm,
	];

	return $travelForm;
}

function extractTravelFormDataFromRegionHtml(string $regionHtml, string $baseUrl): array
{
	if (trim($regionHtml) === '') {
		return emptyTravelFormData();
	}

	$dom = new DOMDocument();
	libxml_use_internal_errors(true);
	$dom->loadHTML($regionHtml);
	libxml_clear_errors();
	$xpath = new DOMXPath($dom);

	$formNode = $xpath->query('//form[contains(@action,"travel.html") and .//input[@name="countryId"] and .//input[@name="regionId"]]')->item(0);
	if (!($formNode instanceof DOMElement)) {
		return emptyTravelFormData();
	}

	$actionRaw = trim((string) $formNode->getAttribute('action'));
	$countryId = trim((string) $xpath->evaluate('string(.//input[@name="countryId"][1]/@value)', $formNode));
	$regionId = trim((string) $xpath->evaluate('string(.//input[@name="regionId"][1]/@value)', $formNode));
	$redirectUrl = trim((string) $xpath->evaluate('string(.//input[@name="redirectUrl"][1]/@value)', $formNode));

	$ticketOptions = [];
	$options = $xpath->query('.//select[@name="ticketQuality"]/option', $formNode);
	if ($options) {
		foreach ($options as $optionNode) {
			if (!($optionNode instanceof DOMElement)) {
				continue;
			}
			$value = trim((string) $optionNode->getAttribute('value'));
			if ($value === '' || !preg_match('/^\d+$/', $value)) {
				continue;
			}
			$label = compactNodeText((string) $optionNode->textContent);
			if ($label === '') {
				$label = 'Q' . $value;
			}
			$ticketOptions[] = [
				'value' => $value,
				'label' => $label,
			];
		}
	}

	return [
		'actionUrl' => resolveUrl($baseUrl, $actionRaw !== '' ? $actionRaw : 'travel.html'),
		'countryId' => $countryId,
		'regionId' => $regionId,
		'redirectUrl' => $redirectUrl,
		'ticketOptions' => $ticketOptions,
	];
}

function extractTravelCountriesFromTravelHtml(string $html): array
{
	if (trim($html) === '') {
		return [];
	}

	$dom = new DOMDocument();
	libxml_use_internal_errors(true);
	$dom->loadHTML($html);
	libxml_clear_errors();
	$xpath = new DOMXPath($dom);

	$countries = [];
	$seen = [];

	$countryNodes = $xpath->query('//form[contains(@class,"travelForm") or contains(@action,"travel.html")]//*[@id="countryListDropdown"]//*[contains(concat(" ", normalize-space(@class), " "), " option ") and @data-country-id]');
	if ($countryNodes) {
		foreach ($countryNodes as $countryNode) {
			if (!($countryNode instanceof DOMElement)) {
				continue;
			}

			$id = trim((string) $countryNode->getAttribute('data-country-id'));
			$nameNode = $xpath->query('.//span[1]', $countryNode)->item(0);
			$name = $nameNode instanceof DOMElement
				? compactNodeText((string) $nameNode->textContent)
				: compactNodeText((string) $countryNode->textContent);

			if (preg_match('/^\d+$/', $id) !== 1 || $name === '' || isset($seen[$id])) {
				continue;
			}

			$seen[$id] = true;
			$countries[] = [
				'id' => $id,
				'name' => $name,
			];
		}
	}

	$selectedCountryNode = $xpath->query('//*[@id="travelSelectedCountry" and @data-country-id][1]')->item(0);
	if ($selectedCountryNode instanceof DOMElement) {
		$selectedId = trim((string) $selectedCountryNode->getAttribute('data-country-id'));
		$selectedNameNode = $xpath->query('.//span[1]', $selectedCountryNode)->item(0);
		$selectedName = $selectedNameNode instanceof DOMElement
			? compactNodeText((string) $selectedNameNode->textContent)
			: compactNodeText((string) $selectedCountryNode->textContent);
		if (preg_match('/^\d+$/', $selectedId) === 1 && $selectedName !== '' && !isset($seen[$selectedId])) {
			$seen[$selectedId] = true;
			$countries[] = [
				'id' => $selectedId,
				'name' => $selectedName,
			];
		}
	}

	if (!empty($countries)) {
		return $countries;
	}

	$countryOptions = $xpath->query('//form[contains(@action,"travel.html")]//select[@name="countryId"]/option');
	if (!$countryOptions || $countryOptions->length === 0) {
		$countryOptions = $xpath->query('//select[@name="countryId"]/option');
	}

	if ($countryOptions) {
		foreach ($countryOptions as $optionNode) {
			if (!($optionNode instanceof DOMElement)) {
				continue;
			}

			$id = trim((string) $optionNode->getAttribute('value'));
			$name = compactNodeText((string) $optionNode->textContent);
			if (preg_match('/^\d+$/', $id) !== 1 || $name === '' || isset($seen[$id])) {
				continue;
			}

			$seen[$id] = true;
			$countries[] = [
				'id' => $id,
				'name' => $name,
			];
		}
	}

	return $countries;
}

function extractCountryRegionsFromHtml(string $html): array
{
	if (trim($html) === '') {
		return [];
	}

	$dom = new DOMDocument();
	libxml_use_internal_errors(true);
	$dom->loadHTML($html);
	libxml_clear_errors();
	$xpath = new DOMXPath($dom);

	$regions = [];
	$seen = [];

	$dropdownRegionNodes = $xpath->query('//*[@id="regionListDropdown"]//*[contains(concat(" ", normalize-space(@class), " "), " option ")]');
	if (!$dropdownRegionNodes || $dropdownRegionNodes->length === 0) {
		$dropdownRegionNodes = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " option ") and @data-region-id]');
	}
	if ($dropdownRegionNodes) {
		foreach ($dropdownRegionNodes as $regionNode) {
			if (!($regionNode instanceof DOMElement)) {
				continue;
			}

			$id = trim((string) $regionNode->getAttribute('data-region-id'));
			if ($id === '') {
				$id = trim((string) $regionNode->getAttribute('data-id'));
			}
			if ($id === '') {
				$onclick = (string) $regionNode->getAttribute('onclick');
				if (preg_match('/region(?:Id)?\s*[=:]\s*["\']?(\d+)/i', $onclick, $matchOnclick) === 1) {
					$id = (string) ($matchOnclick[1] ?? '');
				}
			}

			$nameNode = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " regionName ")][1]', $regionNode)->item(0);
			if (!($nameNode instanceof DOMElement)) {
				$nameNode = $xpath->query('.//span[1]', $regionNode)->item(0);
			}
			$name = $nameNode instanceof DOMElement
				? compactNodeText((string) $nameNode->textContent)
				: compactNodeText((string) $regionNode->textContent);
			$occupationNode = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " regionOwner ")][1]', $regionNode)->item(0);
			$occupation = $occupationNode instanceof DOMElement
				? compactNodeText((string) $occupationNode->textContent)
				: '';

			if (preg_match('/^\d+$/', $id) !== 1 || $name === '' || isset($seen[$id])) {
				continue;
			}

			$seen[$id] = true;
			$regions[] = [
				'id' => $id,
				'name' => $name,
				'occupation' => $occupation,
			];
		}
	}

	$selectedRegionNode = $xpath->query('//*[@id="travelSelectedRegion" and @data-region-id][1]')->item(0);
	if ($selectedRegionNode instanceof DOMElement) {
		$selectedId = trim((string) $selectedRegionNode->getAttribute('data-region-id'));
		$selectedNameNode = $xpath->query('.//span[1]', $selectedRegionNode)->item(0);
		$selectedName = $selectedNameNode instanceof DOMElement
			? compactNodeText((string) $selectedNameNode->textContent)
			: compactNodeText((string) $selectedRegionNode->textContent);
		if (preg_match('/^\d+$/', $selectedId) === 1 && $selectedName !== '' && !isset($seen[$selectedId])) {
			$seen[$selectedId] = true;
			$regions[] = [
				'id' => $selectedId,
				'name' => $selectedName,
				'occupation' => '',
			];
		}
	}

	if (!empty($regions)) {
		return $regions;
	}

	$optionNodes = $xpath->query('//select[contains(@name,"region") or contains(@id,"region")]/option');
	if ($optionNodes && $optionNodes->length > 0) {
		foreach ($optionNodes as $optionNode) {
			if (!($optionNode instanceof DOMElement)) {
				continue;
			}

			$id = trim((string) $optionNode->getAttribute('value'));
			$name = compactNodeText((string) $optionNode->textContent);
			if (preg_match('/^\d+$/', $id) !== 1 || $name === '' || isset($seen[$id])) {
				continue;
			}

			$seen[$id] = true;
			$regions[] = [
				'id' => $id,
				'name' => $name,
				'occupation' => '',
			];
		}
	}

	if (!empty($regions)) {
		return $regions;
	}

	$linkNodes = $xpath->query('//a[contains(@href,"region.html?id=")]');
	if ($linkNodes) {
		foreach ($linkNodes as $linkNode) {
			if (!($linkNode instanceof DOMElement)) {
				continue;
			}

			$href = trim((string) $linkNode->getAttribute('href'));
			if (preg_match('/[?&]id=(\d+)/', $href, $match) !== 1) {
				continue;
			}

			$id = (string) ($match[1] ?? '');
			$name = compactNodeText((string) $linkNode->textContent);
			if ($id === '' || $name === '' || isset($seen[$id])) {
				continue;
			}

			$seen[$id] = true;
			$regions[] = [
				'id' => $id,
				'name' => $name,
				'occupation' => '',
			];
		}
	}

	return $regions;
}

function fetchCountryRegionsStep($ch, array $defaultHeaders, string $baseUrl, string $countryId): array
{
	$endpointUrl = resolveUrl($baseUrl, 'countryRegions.html');
	$postHeaders = [
		'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
		'Origin: ' . rtrim(serverUrl(''), '/'),
		'Referer: ' . resolveUrl($baseUrl, 'travel.html'),
		'X-Requested-With: XMLHttpRequest',
	];

	$step = curlRequest($ch, $endpointUrl, [
		CURLOPT_POST => true,
		CURLOPT_POSTFIELDS => http_build_query([
			'countryId' => $countryId,
			'darkFormList' => '1',
		]),
		CURLOPT_HTTPHEADER => array_merge($defaultHeaders, $postHeaders),
	]);

	$regions = extractCountryRegionsFromHtml((string) ($step['body'] ?? ''));
	if (!empty($regions)) {
		return $step;
	}

	$fallbackUrl = $endpointUrl . '?countryId=' . rawurlencode($countryId);
	return curlRequest($ch, $fallbackUrl, [
		CURLOPT_POST => false,
		CURLOPT_HTTPGET => true,
		CURLOPT_HTTPHEADER => $defaultHeaders,
	]);
}

function extractOccupiedByFromOccupationText(string $occupationText): string
{
	$text = trim($occupationText);
	if ($text === '') {
		return '';
	}

	if (preg_match('/occupied\s+by\s+(.+)$/iu', $text, $match) === 1) {
		return trim((string) ($match[1] ?? ''));
	}
	if (preg_match('/ocupad[ao]\s+por\s+(.+)$/iu', $text, $match) === 1) {
		return trim((string) ($match[1] ?? ''));
	}

	return '';
}

function loadRegionsManualCatalog(string $path, string $serverHost): array
{
	global $server;

	$default = [
		'generatedAt' => gmdate('c'),
		'server' => $serverHost !== '' ? $serverHost : $server . '.e-sim.org',
		'countryCount' => 0,
		'regionCount' => 0,
		'countries' => [],
	];

	if (!is_file($path)) {
		return $default;
	}

	$raw = @file_get_contents($path);
	if (!is_string($raw) || trim($raw) === '') {
		return $default;
	}

	$decoded = json_decode($raw, true);
	if (!is_array($decoded)) {
		return $default;
	}

	$decoded['countries'] = is_array($decoded['countries'] ?? null) ? array_values($decoded['countries']) : [];
	$decoded['server'] = (string) ($decoded['server'] ?? $default['server']);
	$decoded['countryCount'] = (int) ($decoded['countryCount'] ?? count($decoded['countries']));
	$decoded['regionCount'] = (int) ($decoded['regionCount'] ?? 0);

	if ($decoded['regionCount'] < 1) {
		$decoded['regionCount'] = 0;
		foreach ((array) $decoded['countries'] as $countryItem) {
			if (!is_array($countryItem)) {
				continue;
			}
			$decoded['regionCount'] += is_array($countryItem['regions'] ?? null) ? count($countryItem['regions']) : 0;
		}
	}

	return $decoded;
}

function upsertCountryIntoRegionsManualCatalog(array $catalog, string $countryId, string $countryName, array $regions): array
{
	$countries = is_array($catalog['countries'] ?? null) ? array_values($catalog['countries']) : [];
	$replaced = false;
	foreach ($countries as $idx => $countryItem) {
		if (!is_array($countryItem)) {
			continue;
		}
		if ((string) ($countryItem['id'] ?? '') === $countryId) {
			$countries[$idx] = [
				'id' => $countryId,
				'name' => $countryName,
				'regions' => array_values($regions),
				'regionCount' => count($regions),
			];
			$replaced = true;
			break;
		}
	}

	if (!$replaced) {
		$countries[] = [
			'id' => $countryId,
			'name' => $countryName,
			'regions' => array_values($regions),
			'regionCount' => count($regions),
		];
	}

	usort($countries, static function (array $a, array $b): int {
		return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
	});

	$totalRegions = 0;
	foreach ($countries as $countryItem) {
		$totalRegions += is_array($countryItem['regions'] ?? null) ? count($countryItem['regions']) : 0;
	}

	$catalog['generatedAt'] = gmdate('c');
	$catalog['countries'] = $countries;
	$catalog['countryCount'] = count($countries);
	$catalog['regionCount'] = $totalRegions;

	return $catalog;
}

function saveRegionsManualCatalog(string $path, array $catalog): bool
{
	$json = json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	if (!is_string($json)) {
		return false;
	}

	$dir = dirname($path);
	if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
		return false;
	}

	return @file_put_contents($path, $json . PHP_EOL) !== false;
}

function detectBattleTypeFromRegionHtml(string $regionHtml, string $battleUrl): array
{
	$dom = new DOMDocument();
	libxml_use_internal_errors(true);
	$dom->loadHTML($regionHtml);
	libxml_clear_errors();
	$xpath = new DOMXPath($dom);

	$battleId = extractBattleIdFromUrl($battleUrl);
	$targetMiniTag = null;

	if ($battleId !== '') {
		$miniTags = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " battleMinitag ")]');
		if ($miniTags) {
			foreach ($miniTags as $miniTag) {
				if (!($miniTag instanceof DOMElement)) {
					continue;
				}

				$dataLink = trim((string) $miniTag->getAttribute('data-link'));
				if ($dataLink !== '' && extractBattleIdFromUrl($dataLink) === $battleId) {
					$targetMiniTag = $miniTag;
					break;
				}

				$linkNode = $xpath->query('.//a[contains(@href,"battle.html?id=")][1]', $miniTag)->item(0);
				if ($linkNode instanceof DOMElement) {
					$linkHref = trim((string) $linkNode->getAttribute('href'));
					if (extractBattleIdFromUrl($linkHref) === $battleId) {
						$targetMiniTag = $miniTag;
						break;
					}
				}

				$buttonNode = $xpath->query('.//button[@data-id][1]', $miniTag)->item(0);
				if ($buttonNode instanceof DOMElement && trim((string) $buttonNode->getAttribute('data-id')) === $battleId) {
					$targetMiniTag = $miniTag;
					break;
				}
			}
		}
	}

	if (!($targetMiniTag instanceof DOMElement)) {
		$targetMiniTag = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " battleMinitag ")]')->item(0);
	}

	if (!($targetMiniTag instanceof DOMElement)) {
		return [
			'type' => 'unknown',
			'label' => 'Tipo desconocido',
		];
	}

	$typeNode = $xpath->query('.//div[contains(concat(" ", normalize-space(@class), " "), " battleLocation ")]//span[normalize-space(text())!=""][1]', $targetMiniTag)->item(0);
	$typeText = $typeNode instanceof DOMElement ? compactNodeText((string) $typeNode->textContent) : '';
	return classifyBattleTypeFromText($typeText);
}

function extractBattleIdFromUrl(string $url): string
{
	$parts = parse_url($url);
	if (!is_array($parts)) {
		return '';
	}

	$query = (string) ($parts['query'] ?? '');
	if ($query === '') {
		return '';
	}

	$params = [];
	parse_str($query, $params);
	$id = isset($params['id']) ? trim((string) $params['id']) : '';
	if ($id !== '' && preg_match('/^\d+$/', $id)) {
		return $id;
	}

	return '';
}

function classifyBattleTypeFromText(string $text): array
{
	$normalized = normalizeCountryToken($text);
	if ($normalized === '') {
		return [
			'type' => 'unknown',
			'label' => 'Tipo desconocido',
		];
	}

	if (str_contains($normalized, 'resistance war')
		|| str_contains($normalized, 'resistancewar')
		|| str_contains($normalized, 'guerra de resistencia')) {
		return [
			'type' => 'resistance-war',
			'label' => 'Resistance War',
		];
	}

	if (str_contains($normalized, 'normal battle')
		|| str_contains($normalized, 'battle for')
		|| str_contains($normalized, 'batalla por')
		|| str_contains($normalized, 'direct attack')
		|| str_contains($normalized, 'ataque directo')) {
		return [
			'type' => 'direct-attack',
			'label' => 'Normal Battle',
		];
	}

	return [
		'type' => 'direct-attack',
		'label' => 'Normal Battle',
	];
}

function findNeighborRegionControlledByCountry(string $regionHtml, string $baseUrl, string $battleRegionId, string $country): array
{
	$dom = new DOMDocument();
	libxml_use_internal_errors(true);
	$dom->loadHTML($regionHtml);
	libxml_clear_errors();
	$xpath = new DOMXPath($dom);

	$countryNeedle = normalizeCountryToken($country);
	if ($countryNeedle === '') {
		return [];
	}

	$cards = $xpath->query(
		'//div[contains(concat(" ", normalize-space(@class), " "), " regionViewTiles ") and .//*[contains(translate(normalize-space(text()), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "neighbour region")]]'
		. '//div[contains(concat(" ", normalize-space(@class), " "), " d-grid ") and contains(concat(" ", normalize-space(@class), " "), " grid-col-3 ") and .//a[contains(@href,"region.html?id=")] and .//span[contains(concat(" ", normalize-space(@class), " "), " countryNameTranslated ")] ]'
	);
	if (!$cards || $cards->length === 0) {
		$cards = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " d-grid ") and contains(concat(" ", normalize-space(@class), " "), " grid-col-3 ") and .//a[contains(@href,"region.html?id=")] and .//span[contains(concat(" ", normalize-space(@class), " "), " countryNameTranslated ")] ]');
	}

	if (!$cards || $cards->length === 0) {
		return [];
	}

	foreach ($cards as $card) {
		if (!($card instanceof DOMElement)) {
			continue;
		}

		$anchor = $xpath->query('.//div[contains(concat(" ", normalize-space(@class), " "), " biggerFont ")]//a[contains(@href,"region.html?id=")]', $card)->item(0);
		if (!($anchor instanceof DOMElement)) {
			$anchor = $xpath->query('.//a[contains(@href,"region.html?id=")]', $card)->item(0);
		}
		if (!($anchor instanceof DOMElement)) {
			continue;
		}

		$href = trim((string) $anchor->getAttribute('href'));
		if ($href === '') {
			continue;
		}

		$regionUrl = resolveUrl($baseUrl, $href);
		$regionId = extractRegionIdFromUrl($regionUrl);
		if ($regionId === '' || $regionId === $battleRegionId) {
			continue;
		}

		$matchedCountryOwner = false;

		// Preferred: Current owner is the first row in the third column of each neighbour card.
		$currentOwnerNode = $xpath->query('.//div[contains(concat(" ", normalize-space(@class), " "), " d-flex ") and contains(concat(" ", normalize-space(@class), " "), " flex-column ")]//div[contains(concat(" ", normalize-space(@class), " "), " d-flex ") and contains(concat(" ", normalize-space(@class), " "), " flex-row ")][1]//span[contains(concat(" ", normalize-space(@class), " "), " countryNameTranslated ")][1]', $card)->item(0);
		if ($currentOwnerNode instanceof DOMElement) {
			$currentOwnerText = normalizeCountryToken(compactNodeText((string) $currentOwnerNode->textContent));
			$matchedCountryOwner = countryMatchesToken($currentOwnerText, $countryNeedle);
		}

		// Fallback for slightly different region layouts: first translated country in the card.
		if (!$matchedCountryOwner) {
			$firstOwnerNode = $xpath->query('.//span[contains(concat(" ", normalize-space(@class), " "), " countryNameTranslated ")][1]', $card)->item(0);
			if ($firstOwnerNode instanceof DOMElement) {
				$firstOwnerText = normalizeCountryToken(compactNodeText((string) $firstOwnerNode->textContent));
				$matchedCountryOwner = countryMatchesToken($firstOwnerText, $countryNeedle);
			}
		}

		if (!$matchedCountryOwner) {
			continue;
		}

		$regionName = compactNodeText((string) $anchor->textContent);
		if ($regionName === '') {
			$regionName = 'Region ' . $regionId;
		}

		return [
			'regionId' => $regionId,
			'regionName' => $regionName,
			'regionUrl' => $regionUrl,
		];
	}

	return [];
}

function countryMatchesToken(string $candidate, string $target): bool
{
	if ($candidate === '' || $target === '') {
		return false;
	}

	return $candidate === $target
		|| str_contains($candidate, $target)
		|| str_contains($target, $candidate);
}

function normalizeCountryToken(string $value): string
{
	$value = strtolower($value);
	$value = strtr($value, [
		'á' => 'a',
		'é' => 'e',
		'í' => 'i',
		'ó' => 'o',
		'ú' => 'u',
		'ü' => 'u',
		'ñ' => 'n',
	]);
	$value = preg_replace('/[^a-z0-9 ]+/', ' ', $value);
	$value = preg_replace('/\s+/', ' ', (string) $value);
	return trim((string) $value);
}

function extractBattleRoundId(DOMXPath $xpath): string
{
	$roundNode = $xpath->query('//*[@id="battleRoundId"]')->item(0);
	if ($roundNode instanceof DOMElement) {
		$value = trim((string) $roundNode->getAttribute('value'));
		if ($value !== '' && preg_match('/^\d+$/', $value)) {
			return $value;
		}
	}

	return '';
}

function extractFightRequestUrl(string $html, string $battleUrl): string
{
	if (preg_match('/fetch\(\s*["\']([^"\']*fight[^"\']*\.html[^"\']*)["\']/i', $html, $m)) {
		return resolveUrl($battleUrl, (string) ($m[1] ?? ''));
	}

	if (preg_match('/url\s*:\s*["\']([^"\']*fight[^"\']*\.html[^"\']*)["\']/i', $html, $m)) {
		return resolveUrl($battleUrl, (string) ($m[1] ?? ''));
	}

	return '';
}

function extractFightRequestStaticValue(string $html, string $key): string
{
	$pattern = '/' . preg_quote($key, '/') . '=([^&"\'\s]+)/i';
	if (preg_match($pattern, $html, $m)) {
		return trim((string) ($m[1] ?? ''));
	}

	return '';
}

function extractWeaponQuantityFromBattleHtml(DOMXPath $xpath, string $quality): string
{
	if (!preg_match('/^\d+$/', $quality)) {
		return '';
	}

	$node = $xpath->query('//*[@id="weaponQ' . $quality . '"]')->item(0);
	if (!($node instanceof DOMElement)) {
		return '';
	}

	$quantity = trim((string) preg_replace('/[^0-9]/', '', (string) $node->textContent));
	if ($quantity === '' || !preg_match('/^\d+$/', $quantity)) {
		return '';
	}

	return $quantity;
}

function hasClass(DOMElement $element, string $className): bool
{
	$classes = ' ' . preg_replace('/\s+/', ' ', trim((string) $element->getAttribute('class'))) . ' ';
	return str_contains($classes, ' ' . $className . ' ');
}

function isElementVisible(DOMElement $element): bool
{
	if (hasClass($element, 'hidden') || hasClass($element, 'visibility_hidden')) {
		return false;
	}

	$style = strtolower((string) $element->getAttribute('style'));
	if (str_contains($style, 'display:none') || str_contains($style, 'visibility:hidden')) {
		return false;
	}

	return true;
}

function detectBattlePlayerSide(DOMXPath $xpath, ?DOMElement $leftFightNode, ?DOMElement $rightFightNode): string
{
	if ($leftFightNode instanceof DOMElement && isElementVisible($leftFightNode)) {
		return 'defender';
	}

	if ($rightFightNode instanceof DOMElement && isElementVisible($rightFightNode)) {
		return 'attacker';
	}

	$roundStatus = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " roundStatus ")]')->item(0);
	if ($roundStatus instanceof DOMElement) {
		if (hasClass($roundStatus, 'selectedLeftSide')) {
			return 'defender';
		}
		if (hasClass($roundStatus, 'selectedRightSide')) {
			return 'attacker';
		}
	}

	return '';
}

function extractBattleCountries(DOMXPath $xpath, string $html): array
{
	$countryA = '';
	$countryB = '';

	$battleSlimAllies = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " battleSlimStatus ")]//div[contains(concat(" ", normalize-space(@class), " "), " alliesList ")]');
	if ($battleSlimAllies && $battleSlimAllies->length >= 2) {
		$leftNode = $battleSlimAllies->item(0);
		$rightNode = $battleSlimAllies->item(1);
		if ($leftNode instanceof DOMElement && $rightNode instanceof DOMElement) {
			$left = extractCountryFromAlliesList($xpath, $leftNode);
			$right = extractCountryFromAlliesList($xpath, $rightNode);
			if ($left !== '' && $right !== '' && $left !== $right) {
				return ['countryA' => $left, 'countryB' => $right];
			}
		}
	}

	$battleSlimCountrySpans = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " battleSlimStatus ")]//div[contains(concat(" ", normalize-space(@class), " "), " alliesList ")]//span[normalize-space(text())!=""]');
	if ($battleSlimCountrySpans) {
		$names = [];
		foreach ($battleSlimCountrySpans as $span) {
			if (!($span instanceof DOMElement)) {
				continue;
			}

			$name = compactNodeText((string) $span->textContent);
			if ($name === '' || in_array($name, $names, true)) {
				continue;
			}

			$names[] = $name;
			if (count($names) >= 2) {
				break;
			}
		}

		if (count($names) >= 2) {
			return ['countryA' => $names[0], 'countryB' => $names[1]];
		}
	}

	$attackerNode = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " attacker ")]')->item(0);
	$defenderNode = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " defender ")]')->item(0);
	if ($attackerNode instanceof DOMElement) {
		$countryA = compactNodeText((string) $attackerNode->textContent);
	}
	if ($defenderNode instanceof DOMElement) {
		$countryB = compactNodeText((string) $defenderNode->textContent);
	}

	if ($countryA !== '' && $countryB !== '' && $countryA !== $countryB) {
		return ['countryA' => $countryA, 'countryB' => $countryB];
	}

	$possibleTitles = $xpath->query('//h1|//h2|//h3|//title|//*[contains(@class,"battleHeader")]|//*[contains(@class,"battle-title")]');
	if ($possibleTitles) {
		foreach ($possibleTitles as $node) {
			if (!($node instanceof DOMElement)) {
				continue;
			}

			$text = compactNodeText((string) $node->textContent);
			if (preg_match('/^(.+?)\s+(?:vs\.?|versus|v\.)\s+(.+)$/iu', $text, $m)) {
				$left = trim((string) ($m[1] ?? ''));
				$right = trim((string) ($m[2] ?? ''));
				if ($left !== '' && $right !== '' && $left !== $right) {
					return ['countryA' => $left, 'countryB' => $right];
				}
			}
		}
	}

	if (preg_match('/([A-Za-z][A-Za-z\s]{2,})\s+(?:vs\.?|versus|v\.)\s+([A-Za-z][A-Za-z\s]{2,})/iu', $html, $m)) {
		$left = compactNodeText((string) ($m[1] ?? ''));
		$right = compactNodeText((string) ($m[2] ?? ''));
		if ($left !== '' && $right !== '' && $left !== $right) {
			return ['countryA' => $left, 'countryB' => $right];
		}
	}

	$countryLinks = $xpath->query('//a[contains(@href,"country.html") or contains(@href,"countryId=")]');
	if ($countryLinks) {
		$names = [];
		foreach ($countryLinks as $link) {
			if (!($link instanceof DOMElement)) {
				continue;
			}
			$name = compactNodeText((string) $link->textContent);
			if ($name !== '' && !in_array($name, $names, true)) {
				$names[] = $name;
			}
			if (count($names) >= 2) {
				break;
			}
		}
		if (count($names) >= 2) {
			return ['countryA' => $names[0], 'countryB' => $names[1]];
		}
	}

	return [
		'countryA' => $countryA,
		'countryB' => $countryB,
	];
}

function extractCountryFromAlliesList(DOMXPath $xpath, DOMElement $alliesList): string
{
	$directSpanNodes = $xpath->query('./span[normalize-space(text())!=""]', $alliesList);
	$directSpan = $directSpanNodes instanceof DOMNodeList ? $directSpanNodes->item(0) : null;
	if ($directSpan instanceof DOMElement) {
		$name = compactNodeText((string) $directSpan->textContent);
		if ($name !== '') {
			return $name;
		}
	}

	$directTexts = $xpath->query('./text()[normalize-space(.)!=""]', $alliesList);
	if ($directTexts) {
		foreach ($directTexts as $textNode) {
			$name = compactNodeText((string) $textNode->textContent);
			if ($name !== '') {
				return $name;
			}
		}
	}

	$fallbackSpanNodes = $xpath->query('.//span[normalize-space(text())!="" and not(ancestor::*[contains(concat(" ", normalize-space(@class), " "), " alliesPopup ")])]', $alliesList);
	$fallbackSpan = $fallbackSpanNodes instanceof DOMNodeList ? $fallbackSpanNodes->item(0) : null;
	if ($fallbackSpan instanceof DOMElement) {
		$name = compactNodeText((string) $fallbackSpan->textContent);
		if ($name !== '') {
			return $name;
		}
	}

	return '';
}

function compactNodeText(string $value): string
{
	$text = trim((string) preg_replace('/\s+/', ' ', $value));
	$text = trim($text, " \t\n\r\0\x0B-:|");
	return $text;
}

function detectActionUrlFromElement(DOMXPath $xpath, DOMElement $node, string $baseUrl): string
{
	$href = trim((string) $node->getAttribute('href'));
	if ($href !== '' && $href !== '#') {
		return resolveUrl($baseUrl, $href);
	}

	$dataUrl = trim((string) $node->getAttribute('data-url'));
	if ($dataUrl !== '') {
		return resolveUrl($baseUrl, $dataUrl);
	}

	$onclick = trim((string) $node->getAttribute('onclick'));
	$fromOnclick = extractUrlFromJsString($onclick, $baseUrl);
	if ($fromOnclick !== '') {
		return $fromOnclick;
	}

	$linkNode = $xpath->query('.//a[@href]', $node)->item(0);
	if ($linkNode instanceof DOMElement) {
		$innerHref = trim((string) $linkNode->getAttribute('href'));
		if ($innerHref !== '' && $innerHref !== '#') {
			return resolveUrl($baseUrl, $innerHref);
		}
	}

	$formNode = $xpath->query('ancestor-or-self::form[1]', $node)->item(0);
	if ($formNode instanceof DOMElement) {
		$action = trim((string) $formNode->getAttribute('action'));
		if ($action !== '') {
			return resolveUrl($baseUrl, $action);
		}
	}

	return '';
}

function extractUrlFromJsString(string $source, string $baseUrl): string
{
	if ($source === '') {
		return '';
	}

	if (preg_match_all('/["\']([^"\']+)["\']/', $source, $matches)) {
		foreach ((array) ($matches[1] ?? []) as $candidateRaw) {
			$candidate = trim((string) $candidateRaw);
			if ($candidate === '' || $candidate === '#') {
				continue;
			}

			$normalized = ltrim($candidate, '/');
			if (preg_match('/^(https?:\/\/|[a-zA-Z0-9_\/-]+\.(html|ajax))(\?|$)/', $candidate)
				|| str_contains($normalized, 'ajax')
				|| str_contains($normalized, 'fight')
				|| str_contains($normalized, 'battle')) {
				return resolveUrl($baseUrl, $candidate);
			}
		}
	}

	return '';
}

function submitBattleAction($ch, string $actionUrl, string $refererUrl, array $defaultHeaders, array $payload = []): array
{
	$fallbackRef = serverUrl('index.html');
	$safeReferer = $refererUrl !== '' ? $refererUrl : $fallbackRef;
	$postHeaders = [
		'Content-Type: application/x-www-form-urlencoded',
		'Origin: ' . rtrim(serverUrl(''), '/'),
		'Referer: ' . $safeReferer,
	];
	$postBody = $payload ? http_build_query($payload) : '';

	return curlRequest($ch, $actionUrl, [
		CURLOPT_POST => true,
		CURLOPT_POSTFIELDS => $postBody,
		CURLOPT_HTTPHEADER => array_merge($defaultHeaders, $postHeaders),
	]);
}
?>
<!doctype html>
<html lang="es">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Prueba cURL Login e-sim</title>
	<style>
		body {
			font-family: Segoe UI, Arial, sans-serif;
			margin: 20px;
			line-height: 1.4;
			color: #1c2430;
			background: #f5f7fb;
		}
		h1 { margin: 0; }
		.ok { color: #0a7a2f; }
		.warn { color: #ad6b00; }
		.error { color: #b42318; }
		.page-header {
			overflow: hidden;
			margin-bottom: 8px;
		}
		.header-float-right {
			float: right;
			text-align: right;
			background: #ffffff;
			border: 1px solid #d7e0ee;
			border-radius: 10px;
			padding: 8px 10px;
			min-width: 220px;
		}
		.header-float-row { margin: 0; }
		.header-float-label {
			font-size: 12px;
			color: #4b5d7b;
			margin-right: 6px;
		}
		.header-float-value {
			font-size: 14px;
			font-weight: 600;
			color: #162742;
		}
		.subtitle {
			font-size: 13px;
			color: #4b5d7b;
			margin: 4px 0 12px;
		}
		.meta {
			background: #ffffff;
			border: 1px solid #d7e0ee;
			padding: 12px;
			margin-bottom: 12px;
			border-radius: 10px;
		}
		.player-panel {
			background: linear-gradient(135deg, #eaf2ff 0%, #f6fbff 100%);
			border: 1px solid #b6c8e6;
			padding: 16px;
			margin-bottom: 16px;
			border-radius: 12px;
			box-shadow: 0 10px 24px rgba(20, 47, 95, 0.08);
		}
		.player-toolbar {
			display: flex;
			justify-content: space-between;
			align-items: center;
			gap: 10px;
			margin-bottom: 10px;
		}
		.train-form { margin: 0; }
		.train-button {
			border: 1px solid #9fb3d8;
			background: #ffffff;
			color: #243c63;
			border-radius: 8px;
			padding: 8px 12px;
			font-weight: 600;
			cursor: pointer;
		}
		.train-button:hover { background: #f4f8ff; }
		.player-grid {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
			gap: 10px;
		}
		.player-card {
			background: #fff;
			border: 1px solid #d7e1f2;
			padding: 10px;
			border-radius: 8px;
		}
		.player-label {
			display: block;
			font-size: 12px;
			color: #4b5d7b;
			margin-bottom: 3px;
		}
		.player-value {
			font-size: 15px;
			font-weight: 600;
			color: #162742;
		}
		.section-panel {
			background: #ffffff;
			border: 1px solid #d7e0ee;
			border-radius: 12px;
			padding: 14px;
			margin-bottom: 14px;
		}
		.section-title {
			margin: 0 0 8px;
			font-size: 18px;
			color: #162742;
		}
		.battles-list {
			margin: 0;
			padding-left: 18px;
		}
		.battles-list li {
			margin: 6px 0;
		}
		.battles-list a {
			color: #1f4f98;
			text-decoration: none;
		}
		.battles-list a:hover {
			text-decoration: underline;
		}
		.section-meta {
			font-size: 12px;
			color: #4b5d7b;
			margin: 0 0 8px;
		}
		.battles-cards {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(340px, 1fr));
			gap: 12px;
		}
		.battle-card {
			background: linear-gradient(180deg, #fbfcff 0%, #f3f7ff 100%);
			border: 1px solid #cfdbef;
			border-radius: 12px;
			padding: 12px;
			box-shadow: 0 8px 18px rgba(20, 47, 95, 0.06);
		}
		.battle-card-header {
			display: flex;
			justify-content: space-between;
			align-items: baseline;
			gap: 8px;
			margin-bottom: 10px;
		}
		.battle-city {
			margin: 0;
			font-size: 16px;
			font-weight: 700;
			color: #162742;
		}
		.battle-link {
			font-size: 12px;
			color: #1f4f98;
			text-decoration: none;
		}
		.battle-link:hover {
			text-decoration: underline;
		}
		.battle-lanes {
			display: grid;
			grid-template-columns: 1fr 1fr;
			gap: 10px;
		}
		.battle-lane {
			background: #ffffff;
			border: 1px solid #d8e2f2;
			border-radius: 10px;
			padding: 10px;
		}
		.battle-lane.left {
			border-left: 4px solid #2f6fbf;
		}
		.battle-lane.right {
			border-right: 4px solid #b83939;
		}
		.battle-lane-role {
			display: block;
			font-size: 11px;
			text-transform: uppercase;
			letter-spacing: 0.03em;
			font-weight: 700;
			color: #4b5d7b;
			margin-bottom: 2px;
		}
		.battle-lane-country {
			font-size: 14px;
			font-weight: 700;
			color: #182a47;
			margin-bottom: 8px;
		}
		.battle-side-form {
			margin: 0;
			display: flex;
			flex-wrap: wrap;
			gap: 6px;
			align-items: center;
		}
		.battle-side-hint {
			font-size: 11px;
			color: #6b7d98;
			width: 100%;
		}
		.battle-card-meta {
			font-size: 11px;
			color: #607390;
			margin-top: 8px;
		}
		.battle-status {
			display: inline-block;
			padding: 2px 8px;
			border-radius: 999px;
			font-size: 11px;
			font-weight: 600;
			margin-left: 6px;
		}
		.battle-status-ok {
			background: #e8f5eb;
			color: #1e7a39;
			border: 1px solid #b9e4c3;
		}
		.battle-status-no {
			background: #fdeaea;
			color: #a82d2d;
			border: 1px solid #f2c4c4;
		}
		.battle-actions {
			display: flex;
			gap: 8px;
			flex-wrap: wrap;
			margin-top: 6px;
		}
		.battle-action-form {
			margin: 0;
		}
		.battle-action-button {
			border: 1px solid #9fb3d8;
			background: #ffffff;
			color: #243c63;
			border-radius: 8px;
			padding: 5px 9px;
			font-size: 12px;
			font-weight: 600;
			cursor: pointer;
		}
		.battle-action-button:hover {
			background: #f4f8ff;
		}
		.action-toast-container {
			position: fixed;
			top: 14px;
			right: 14px;
			z-index: 9999;
			display: flex;
			flex-direction: column;
			gap: 8px;
		}
		.action-toast {
			min-width: 220px;
			max-width: 380px;
			padding: 10px 12px;
			border-radius: 9px;
			background: #1f2d44;
			color: #ffffff;
			font-size: 13px;
			box-shadow: 0 8px 18px rgba(0, 0, 0, 0.22);
			opacity: 0;
			transform: translateY(-6px);
			transition: opacity 0.2s ease, transform 0.2s ease;
		}
		.action-toast.show {
			opacity: 1;
			transform: translateY(0);
		}
		.action-toast.error {
			background: #8e2323;
		}
		.action-toast.success {
			background: #1f5f35;
		}
		.debug-panel {
			background: #ffffff;
			border: 1px solid #d7e0ee;
			border-radius: 10px;
			padding: 12px;
			margin-top: 12px;
		}
		.debug-panel summary {
			cursor: pointer;
			font-weight: 600;
			color: #243c63;
		}
		.debug-panel pre {
			margin-top: 10px;
			max-height: 420px;
			overflow: auto;
			padding: 10px;
			background: #f7f9fc;
			border: 1px solid #d7e0ee;
			border-radius: 8px;
			white-space: pre-wrap;
			word-break: break-word;
			font-size: 12px;
			line-height: 1.35;
		}
	</style>
</head>
<body>
	<div class="page-header">
		<div class="header-float-right">
			<div class="header-float-row" style="display:flex;align-items:center;justify-content:flex-end;gap:10px;flex-wrap:wrap;">
				<span><span class="header-float-label">Day:</span><span class="header-float-value"><?= htmlspecialchars((string) ($playerInfo['day'] ?: '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span></span>
				<span><span class="header-float-label">Location:</span><span class="header-float-value"><?= htmlspecialchars((string) ($playerInfo['location'] ?: '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span></span>
				<form method="post" style="display:inline-flex;align-items:center;margin:0;">
					<input type="hidden" name="action" value="logout-now">
					<button type="submit" class="train-button" style="border-color:#d2a1a1;color:#7d1e1e;padding:4px 8px;font-size:12px;">Cerrar sesion</button>
				</form>
			</div>
		</div>
		<h1><?= htmlspecialchars($username, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
	</div>

	<?php if (!$authenticated): ?>
		<div class="meta">
			<p class="error"><strong>Note:</strong> login may require captcha or additional anti-bot fields.</p>
			<?php if ($registrationAttempted): ?>
				<p class="warn"><strong>Registro avanzado:</strong> intento realizado (countryId 26 / USA), resultado: <?= htmlspecialchars((string) ($registrationResult['reason'] ?? 'unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?= isset($registrationResult['httpStatus']) ? ' | HTTP ' . (int) $registrationResult['httpStatus'] : '' ?></p>
			<?php elseif ($registrationAutoBlocked): ?>
				<p class="ok"><strong>Registro automatico:</strong> bloqueado por seguridad (no se crearan usuarios nuevos).</p>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php if (!empty($logoutResult['attempted'])): ?>
		<div class="meta">
			<p class="<?= !empty($logoutResult['saved']) ? 'ok' : 'warn' ?>"><strong>Cerrar sesion:</strong> <?= htmlspecialchars((string) ($logoutResult['reason'] ?? 'unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?= isset($logoutResult['httpStatus']) ? ' | HTTP ' . (int) $logoutResult['httpStatus'] : '' ?><?= !empty($logoutResult['url']) ? ' | ' . htmlspecialchars((string) $logoutResult['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?></p>
		</div>
	<?php endif; ?>

	<?php if ($trainAttempted): ?>
		<div class="meta">
			<p class="<?= !empty($trainResult['saved']) ? 'ok' : 'warn' ?>"><strong>Entrenar:</strong> <?= htmlspecialchars((string) ($trainResult['reason'] ?? 'unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?= isset($trainResult['httpStatus']) ? ' | HTTP ' . (int) $trainResult['httpStatus'] : '' ?></p>
		</div>
	<?php endif; ?>

	<?php if ($workAttempted): ?>
		<div class="meta">
			<p class="<?= !empty($workResult['saved']) ? 'ok' : 'warn' ?>"><strong>Trabajar:</strong> <?= htmlspecialchars((string) ($workResult['reason'] ?? 'unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?= isset($workResult['httpStatus']) ? ' | HTTP ' . (int) $workResult['httpStatus'] : '' ?></p>
		</div>
	<?php endif; ?>

	<?php if ($eatAttempted): ?>
		<div class="meta">
			<p class="<?= !empty($eatResult['saved']) ? 'ok' : 'warn' ?>"><strong>Comer:</strong> <?= htmlspecialchars((string) ($eatResult['reason'] ?? 'unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?= isset($eatResult['httpStatus']) ? ' | HTTP ' . (int) $eatResult['httpStatus'] : '' ?><?= !empty($eatResult['energy']) ? ' | Energia ' . htmlspecialchars((string) $eatResult['energy'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?></p>
		</div>
	<?php endif; ?>

	<?php if ($drinkAttempted): ?>
		<div class="meta">
			<p class="<?= !empty($drinkResult['saved']) ? 'ok' : 'warn' ?>"><strong>Beber:</strong> <?= htmlspecialchars((string) ($drinkResult['reason'] ?? 'unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?= isset($drinkResult['httpStatus']) ? ' | HTTP ' . (int) $drinkResult['httpStatus'] : '' ?><?= !empty($drinkResult['energy']) ? ' | Energia ' . htmlspecialchars((string) $drinkResult['energy'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?></p>
		</div>
	<?php endif; ?>

	<?php if ($leaveJobAttempted): ?>
		<div class="meta">
			<p class="<?= !empty($leaveJobResult['saved']) ? 'ok' : 'warn' ?>"><strong>Renunciar:</strong> <?= htmlspecialchars((string) ($leaveJobResult['reason'] ?? 'unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?= isset($leaveJobResult['httpStatus']) ? ' | HTTP ' . (int) $leaveJobResult['httpStatus'] : '' ?></p>
		</div>
	<?php endif; ?>

	<?php if ($travelAttempted): ?>
		<div class="meta">
			<p class="<?= !empty($travelResult['saved']) ? 'ok' : 'warn' ?>"><strong>Viajar:</strong> <?= htmlspecialchars((string) ($travelResult['reason'] ?? 'unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?= isset($travelResult['httpStatus']) ? ' | HTTP ' . (int) $travelResult['httpStatus'] : '' ?><?= !empty($travelResult['destination']) ? ' | ' . htmlspecialchars((string) $travelResult['destination'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?></p>
		</div>
	<?php endif; ?>

	<?php if ($battleActionAttempted): ?>
		<div class="meta">
			<p class="<?= !empty($battleActionResult['saved']) ? 'ok' : 'warn' ?>"><strong><?= ($battleActionResult['type'] ?? '') === 'battle-change-side' ? 'Cambiar lado' : 'Atacar' ?>:</strong> <?= htmlspecialchars((string) ($battleActionResult['reason'] ?? 'unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?= isset($battleActionResult['httpStatus']) ? ' | HTTP ' . (int) $battleActionResult['httpStatus'] : '' ?><?= !empty($battleActionResult['battleTitle']) ? ' | ' . htmlspecialchars((string) $battleActionResult['battleTitle'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?></p>
		</div>
	<?php endif; ?>

	<?php if (!empty($battleInspectResult['attempted'])): ?>
		<div class="meta">
			<p class="<?= !empty($battleInspectResult['saved']) ? 'ok' : 'warn' ?>"><strong>Cargar batalla:</strong> <?= htmlspecialchars((string) ($battleInspectResult['reason'] ?? 'unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?= isset($battleInspectResult['httpStatus']) ? ' | HTTP ' . (int) $battleInspectResult['httpStatus'] : '' ?><?= !empty($battleInspectResult['battleTitle']) ? ' | ' . htmlspecialchars((string) $battleInspectResult['battleTitle'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?></p>
		</div>
	<?php endif; ?>

	<?php if (!empty($notificationsResult['attempted'])): ?>
		<div class="meta">
			<p class="<?= !empty($notificationsResult['saved']) ? 'ok' : 'warn' ?>"><strong>Notificaciones:</strong> <?= htmlspecialchars((string) ($notificationsResult['reason'] ?? 'unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?= isset($notificationsResult['httpStatus']) ? ' | HTTP ' . (int) $notificationsResult['httpStatus'] : '' ?><?= isset($notificationsResult['bodyLength']) ? ' | HTML ' . number_format((int) $notificationsResult['bodyLength']) . ' bytes' : '' ?><?= isset($notificationsResult['itemsCount']) ? ' | Items ' . (int) $notificationsResult['itemsCount'] : '' ?></p>
		</div>
	<?php endif; ?>

	<?php if (!empty($productMarketResult['attempted'])): ?>
		<div class="meta">
			<p class="<?= !empty($productMarketResult['saved']) ? 'ok' : 'warn' ?>"><strong>Mercado productos:</strong> <?= htmlspecialchars((string) ($productMarketResult['reason'] ?? 'unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?= isset($productMarketResult['httpStatus']) ? ' | HTTP ' . (int) $productMarketResult['httpStatus'] : '' ?><?= isset($productMarketResult['bodyLength']) ? ' | HTML ' . number_format((int) $productMarketResult['bodyLength']) . ' bytes' : '' ?></p>
		</div>
	<?php endif; ?>

	<?php if (!empty($productMarketOffersResult['attempted'])): ?>
		<div class="meta">
			<p class="<?= !empty($productMarketOffersResult['saved']) ? 'ok' : 'warn' ?>"><strong>Ofertas mercado:</strong> <?= htmlspecialchars((string) ($productMarketOffersResult['reason'] ?? 'unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?= isset($productMarketOffersResult['httpStatus']) ? ' | HTTP ' . (int) $productMarketOffersResult['httpStatus'] : '' ?><?= isset($productMarketOffersResult['itemsCount']) ? ' | Items ' . (int) $productMarketOffersResult['itemsCount'] : '' ?><?= !empty($productMarketOffersResult['type']) ? ' | ' . htmlspecialchars((string) $productMarketOffersResult['type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?><?= !empty($productMarketOffersResult['quality']) ? ' Q' . htmlspecialchars((string) $productMarketOffersResult['quality'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?></p>
		</div>
	<?php endif; ?>

	<?php
	$reloadPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
	if ($reloadPath === '') {
		$reloadPath = (string) ($_SERVER['PHP_SELF'] ?? 'index.php');
	}
	?>

	<div class="player-panel">
		<div class="player-toolbar">
			<a href="<?= htmlspecialchars($reloadPath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="train-button" style="display:inline-flex;align-items:center;text-decoration:none;">Recargar</a>
			<div style="display:flex; gap:8px;">
				<form class="train-form js-async-action" method="post" style="display:flex;align-items:center;gap:6px;">
					<input type="hidden" name="action" value="eat-now">
					<select name="eat_quality" class="battle-action-button" style="padding:3px 6px;height:30px;">
						<option value="2">Comida Q2</option>
						<option value="5" selected>Comida Q5</option>
					</select>
					<button type="submit" class="train-button">Comer</button>
				</form>
				<form class="train-form js-async-action" method="post" style="display:flex;align-items:center;gap:6px;">
					<input type="hidden" name="action" value="drink-now">
					<select name="drink_quality" class="battle-action-button" style="padding:3px 6px;height:30px;">
						<option value="2">Bebida Q2</option>
						<option value="5" selected>Bebida Q5</option>
					</select>
					<button type="submit" class="train-button">Beber</button>
				</form>
				<?php if ($showWorkButton): ?>
					<form class="train-form js-async-action" method="post">
						<input type="hidden" name="action" value="work-now">
						<button type="submit" class="train-button">Trabajar</button>
					</form>
				<?php endif; ?>
				<?php if ($showTrainButton): ?>
					<form class="train-form js-async-action" method="post">
						<input type="hidden" name="action" value="train-now">
						<button type="submit" class="train-button">Entrenar</button>
					</form>
				<?php endif; ?>
			</div>
		</div>

		<?php if ($authenticated && $playerInfo['found']): ?>
			<div class="player-grid">
				<div class="player-card">
					<span class="player-label">Energia</span>
					<div class="player-value" id="panelEnergyValue"><?= htmlspecialchars((string) ($playerInfo['energy'] ?: '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
				</div>
				<div class="player-card">
					<span class="player-label">Time</span>
					<div class="player-value"><?= htmlspecialchars((string) ($playerInfo['time'] ?: '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
				</div>
				<div class="player-card">
					<span class="player-label">Level</span>
					<div class="player-value"><?= htmlspecialchars((string) ($playerInfo['level'] ?: '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> | XP <?= htmlspecialchars((string) ($playerInfo['experience'] ?: '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
				</div>
				<div class="player-card">
					<span class="player-label">Rank</span>
					<div class="player-value"><?= htmlspecialchars((string) ($playerInfo['rankTitle'] ?: '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> | AR <?= htmlspecialchars((string) ($playerInfo['attackRank'] ?: '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
				</div>
				<div class="player-card">
					<span class="player-label">Economic Skill</span>
					<div class="player-value"><?= htmlspecialchars((string) ($playerInfo['economicSkill'] ?: '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
				</div>
				<div class="player-card">
					<span class="player-label">Fortaleza</span>
					<div class="player-value"><?= htmlspecialchars((string) ($playerInfo['strength'] ?: '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
				</div>
			</div>
		<?php else: ?>
			<p class="error" style="margin: 0;">Unable to extract player data. Verify that session is active.</p>
		<?php endif; ?>
	</div>

	<div class="section-panel" id="battles-panel">
		<h2 class="section-title">Trabajo</h2>
		<p class="section-meta">
			Fuente: <?= htmlspecialchars((string) ($workplaceResult['url'] ?? $workplaceUrl), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
			<?= isset($workplaceResult['httpStatus']) ? ' | HTTP ' . (int) $workplaceResult['httpStatus'] : '' ?>
		</p>

		<?php if (empty($workplaceResult['attempted'])): ?>
			<p class="warn" style="margin:0;">No se consulto aun la seccion de trabajo.</p>
		<?php elseif (empty($workplaceResult['saved'])): ?>
			<p class="warn" style="margin:0;">No se pudo cargar trabajo (<?= htmlspecialchars((string) ($workplaceResult['reason'] ?? 'unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>).</p>
		<?php else: ?>
			<?php
			$ownerNameValue = trim((string) ($workplaceResult['companyOwner'] ?? ''));
			$ownerTypeValue = trim((string) ($workplaceResult['companyOwnerType'] ?? ''));
			$ownerUrlValue = trim((string) ($workplaceResult['companyOwnerUrl'] ?? ''));
			$ownerDisplayValue = $ownerNameValue !== '' ? $ownerNameValue : '-';
			if ($ownerNameValue !== '' && $ownerTypeValue === 'military_unit') {
				$ownerDisplayValue .= ' (Unidad militar)';
			}
			?>
			<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap;">
				<div>
					<p style="margin:0 0 6px;"><strong>Empresa actual:</strong>
						<?php if ((string) ($workplaceResult['companyUrl'] ?? '') !== ''): ?>
							<a class="battle-link" href="<?= htmlspecialchars((string) ($workplaceResult['companyUrl'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars((string) (($workplaceResult['companyName'] ?? '') !== '' ? (string) $workplaceResult['companyName'] : 'Ver empresa'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
						<?php else: ?>
							<?= htmlspecialchars((string) (($workplaceResult['companyName'] ?? '') !== '' ? (string) $workplaceResult['companyName'] : 'No detectada'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
						<?php endif; ?>
					</p>
					<p style="margin:0;"><strong>Owner:</strong>
						<?php if ($ownerUrlValue !== '' && $ownerNameValue !== ''): ?>
							<a class="battle-link" href="<?= htmlspecialchars($ownerUrlValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($ownerDisplayValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
						<?php else: ?>
							<?= htmlspecialchars($ownerDisplayValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
						<?php endif; ?>
					</p>
				</div>
				<div style="display:flex;gap:8px;flex-wrap:wrap;">
					<?php if (!empty($workplaceResult['canWork']) || $showWorkButton): ?>
						<form class="train-form js-async-action" method="post">
							<input type="hidden" name="action" value="work-now">
							<button type="submit" class="train-button">Trabajar</button>
						</form>
					<?php endif; ?>
					<?php if (!empty($workplaceResult['canLeave'])): ?>
						<form class="train-form js-async-action" method="post">
							<input type="hidden" name="action" value="leave-job-now">
							<button type="submit" class="train-button" style="border-color:#d2a1a1;color:#7d1e1e;">Renunciar</button>
						</form>
					<?php endif; ?>
				</div>
			</div>

			<?php
			$companyUrlInputValue = trim((string) ($_POST['company_url'] ?? ''));
			if ($companyUrlInputValue === '') {
				$companyUrlInputValue = (string) ($workplaceResult['companyUrl'] ?? '');
			}
			$hasRegisteredCompany = trim((string) ($workplaceResult['companyName'] ?? '')) !== ''
				|| trim((string) ($workplaceResult['companyUrl'] ?? '')) !== '';
			?>
			<?php if (!$hasRegisteredCompany): ?>
				<div style="margin-top:12px; padding-top:10px; border-top:1px solid #d7d7d7;">
					<form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
						<input type="hidden" name="action" value="company-offers-load">
						<input
							type="url"
							name="company_url"
							required
							placeholder="<?= htmlspecialchars(serverUrl('company.html?id=1609'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
							value="<?= htmlspecialchars($companyUrlInputValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
							style="min-width:320px;flex:1;padding:6px 8px;border:1px solid #c9c9c9;border-radius:6px;"
						>
						<button type="submit" class="train-button">Cargar ofertas</button>
					</form>

					<?php if (!empty($companyOffersResult['attempted'])): ?>
						<p class="section-meta" style="margin:8px 0 0;">
							Consulta: <?= htmlspecialchars((string) ($companyOffersResult['sourceUrl'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
							<?= isset($companyOffersResult['httpStatus']) ? ' | HTTP ' . (int) $companyOffersResult['httpStatus'] : '' ?>
						</p>
						<?php if (empty($companyOffersResult['saved'])): ?>
							<p class="warn" style="margin:8px 0 0;">No se pudo cargar la empresa (<?= htmlspecialchars((string) ($companyOffersResult['reason'] ?? 'unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>).</p>
						<?php elseif (empty($companyOffersResult['offers']) || !is_array($companyOffersResult['offers'])): ?>
							<p class="warn" style="margin:8px 0 0;">No se detectaron ofertas de trabajo en esa empresa.</p>
						<?php else: ?>
							<p style="margin:8px 0 0;"><strong>Empresa consultada:</strong> <?= htmlspecialchars((string) (($companyOffersResult['companyName'] ?? '') !== '' ? (string) $companyOffersResult['companyName'] : '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
							<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(270px,1fr));gap:10px;margin-top:10px;">
								<?php foreach ((array) ($companyOffersResult['offers'] ?? []) as $offer): ?>
									<div style="border:1px solid #d7d7d7;border-radius:8px;padding:10px;background:#fff;">
										<p style="margin:0 0 6px;"><strong>Oferta #<?= htmlspecialchars((string) ($offer['id'] ?? '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></p>
										<p style="margin:0 0 4px;"><strong>Producto:</strong> <?= htmlspecialchars((string) (($offer['product'] ?? '') !== '' ? (string) $offer['product'] : '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
										<p style="margin:0 0 4px;"><?= htmlspecialchars((string) (($offer['salary'] ?? '') !== '' ? (string) $offer['salary'] : '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
										<p style="margin:0 0 4px;"><strong>Eco minima:</strong> <?= htmlspecialchars((string) (($offer['minSkill'] ?? '') !== '' ? (string) $offer['minSkill'] : '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
										<p style="margin:0 0 4px;"><strong>Empresa:</strong> <?= htmlspecialchars((string) (($offer['company'] ?? '') !== '' ? (string) $offer['company'] : '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
										<p style="margin:0 0 8px;"><?= htmlspecialchars((string) (($offer['employer'] ?? '') !== '' ? (string) $offer['employer'] : '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
										<form class="js-async-action" method="post" style="margin:0;">
											<input type="hidden" name="action" value="company-offer-apply">
											<input type="hidden" name="offer_id" value="<?= htmlspecialchars((string) ($offer['id'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
											<input type="hidden" name="offer_country_id" value="<?= htmlspecialchars((string) ($offer['countryId'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
											<input type="hidden" name="offer_apply_action_url" value="<?= htmlspecialchars((string) ($offer['applyActionUrl'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
											<input type="hidden" name="offer_referer_url" value="<?= htmlspecialchars((string) ($offer['refererUrl'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
											<button type="submit" class="train-button" style="width:100%;">Postularme</button>
										</form>
									</div>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		<?php endif; ?>
	</div>

	<div class="section-panel">
		<h2 class="section-title">Cuenta</h2>
		<p class="section-meta" style="margin:0;">Cambiar correo y reenviar correo de confirmacion de la cuenta.</p>
		<?php
		$changeEmailPrefillValue = trim((string) ($_POST['change_email'] ?? ''));
		if ($changeEmailPrefillValue === '') {
			$changeEmailPrefillValue = trim((string) ($registeredEmailResult['email'] ?? ''));
		}
		?>
		<?php if (!empty($registeredEmailResult['attempted']) && !empty($registeredEmailResult['saved'])): ?>
			<p class="section-meta" style="margin:6px 0 0;">
				Correo registrado: <?= htmlspecialchars((string) (($registeredEmailResult['email'] ?? '') !== '' ? $registeredEmailResult['email'] : 'No detectado'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
				<?= !empty($registeredEmailResult['url']) ? ' | URL ' . htmlspecialchars((string) $registeredEmailResult['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
				<?= isset($registeredEmailResult['httpStatus']) ? ' | HTTP ' . (int) $registeredEmailResult['httpStatus'] : '' ?>
			</p>
		<?php elseif (!empty($registeredEmailResult['attempted'])): ?>
			<p class="warn" style="margin:6px 0 0;">
				No se pudo leer el correo registrado (<?= htmlspecialchars((string) ($registeredEmailResult['reason'] ?? 'unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>)
				<?= isset($registeredEmailResult['httpStatus']) ? ' | HTTP ' . (int) $registeredEmailResult['httpStatus'] : '' ?>
				<?= !empty($registeredEmailResult['error']) ? ' | Error cURL: ' . htmlspecialchars((string) $registeredEmailResult['error'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
			</p>
		<?php endif; ?>
		<form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:10px;">
			<input type="hidden" name="action" value="change-email">
			<label for="change_email_input"><strong>Nuevo Email:</strong></label>
			<input id="change_email_input" type="email" name="change_email" value="<?= htmlspecialchars($changeEmailPrefillValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="correo@dominio.com" required style="min-width:280px;padding:6px 8px;border:1px solid #c7d5ea;border-radius:8px;">
			<button type="submit" class="train-button">Cambiar e-mail</button>
		</form>

		<form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:8px;">
			<input type="hidden" name="action" value="resend-confirmation-mail">
			<button type="submit" class="train-button">Solicitar correo de confirmacion</button>
			<span class="section-meta">GET: <?= htmlspecialchars(serverUrl('resendConfirmationMail.html'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
		</form>

		<form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:8px;">
			<input type="hidden" name="action" value="party-status-check">
			<button type="submit" class="train-button">Validar estado de partido</button>
			<span class="section-meta">GET: <?= htmlspecialchars(serverUrl('myParty.html'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
		</form>

		<?php
		$partyInspectUrlInput = trim((string) ($_POST['party_url'] ?? ''));
		if ($partyInspectUrlInput === '') {
			$partyInspectUrlInput = serverUrl('party.html?id=166');
		}
		?>
		<form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:8px;">
			<input type="hidden" name="action" value="party-inspect-url">
			<label for="party_url_input"><strong>URL partido:</strong></label>
			<input id="party_url_input" type="url" name="party_url" value="<?= htmlspecialchars($partyInspectUrlInput, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="<?= htmlspecialchars(serverUrl('party.html?id=16'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required style="min-width:360px;padding:6px 8px;border:1px solid #c7d5ea;border-radius:8px;">
			<button type="submit" class="train-button">Inspeccionar partido</button>
		</form>

		<?php
		$confirmCodeInputValue = trim((string) ($_POST['confirm_mail_code'] ?? ''));
		$confirmCitizenIdInputValue = preg_replace('/\D+/', '', trim((string) ($_POST['confirm_citizen_id'] ?? '')));
		if ($confirmCitizenIdInputValue === '') {
			$confirmCitizenIdInputValue = preg_replace('/\D+/', '', trim((string) $headerCitizenId));
		}
		if ($confirmCitizenIdInputValue === '') {
			$confirmCitizenIdInputValue = preg_replace('/\D+/', '', trim((string) $userId));
		}
		$hasDynamicConfirmCitizenId = $confirmCitizenIdInputValue !== '';
		$confirmCitizenIdPreview = $hasDynamicConfirmCitizenId ? $confirmCitizenIdInputValue : 'TU_CITIZEN_ID';
		?>
		<form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:8px;">
			<input type="hidden" name="action" value="confirm-mail-code">
			<?php if ($hasDynamicConfirmCitizenId): ?>
				<input type="hidden" name="confirm_citizen_id" value="<?= htmlspecialchars($confirmCitizenIdInputValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
				<span class="section-meta">Citizen ID detectado: <?= htmlspecialchars($confirmCitizenIdInputValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
			<?php else: ?>
				<label for="confirm_citizen_id_input"><strong>Citizen ID:</strong></label>
				<input id="confirm_citizen_id_input" type="text" name="confirm_citizen_id" value="" placeholder="Ingresa tu citizen id" required pattern="[0-9]+" inputmode="numeric" style="min-width:160px;padding:6px 8px;border:1px solid #c7d5ea;border-radius:8px;">
			<?php endif; ?>
			<label for="confirm_mail_code_input"><strong>Codigo confirmacion:</strong></label>
			<input id="confirm_mail_code_input" type="text" name="confirm_mail_code" value="<?= htmlspecialchars($confirmCodeInputValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="Pegue aqui el stamp" required style="min-width:280px;padding:6px 8px;border:1px solid #c7d5ea;border-radius:8px;">
			<button type="submit" class="train-button">Confirmar correo</button>
			<span class="section-meta">GET: <?= htmlspecialchars(serverUrl('confirmMail.html'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>?citizenId=<?= htmlspecialchars($confirmCitizenIdPreview, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>&stamp=CODE</span>
		</form>

		<?php if (!empty($changeEmailResult['attempted'])): ?>
			<p class="<?= !empty($changeEmailResult['saved']) ? 'ok' : 'warn' ?>" style="margin:8px 0 0;">
				Cambio email: <?= htmlspecialchars((string) ($changeEmailResult['reason'] ?? 'unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
				<?= !empty($changeEmailResult['email']) ? ' | Email ' . htmlspecialchars((string) $changeEmailResult['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
				<?= !empty($changeEmailResult['url']) ? ' | URL ' . htmlspecialchars((string) $changeEmailResult['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
				<?= isset($changeEmailResult['httpStatus']) ? ' | HTTP ' . (int) $changeEmailResult['httpStatus'] : '' ?>
				<?= !empty($changeEmailResult['error']) ? ' | Error cURL: ' . htmlspecialchars((string) $changeEmailResult['error'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
			</p>
			<?php if (!empty($changeEmailResult['responseSnippet'])): ?>
				<p class="section-meta" style="margin:6px 0 0;">Respuesta: <?= htmlspecialchars((string) $changeEmailResult['responseSnippet'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
			<?php endif; ?>
		<?php endif; ?>

		<?php if (!empty($resendConfirmationMailResult['attempted'])): ?>
			<p class="<?= !empty($resendConfirmationMailResult['saved']) ? 'ok' : 'warn' ?>" style="margin:8px 0 0;">
				Reenvio confirmacion: <?= htmlspecialchars((string) ($resendConfirmationMailResult['reason'] ?? 'unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
				<?= !empty($resendConfirmationMailResult['url']) ? ' | URL ' . htmlspecialchars((string) $resendConfirmationMailResult['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
				<?= isset($resendConfirmationMailResult['httpStatus']) ? ' | HTTP ' . (int) $resendConfirmationMailResult['httpStatus'] : '' ?>
				<?= !empty($resendConfirmationMailResult['error']) ? ' | Error cURL: ' . htmlspecialchars((string) $resendConfirmationMailResult['error'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
			</p>
			<?php if (!empty($resendConfirmationMailResult['responseSnippet'])): ?>
				<p class="section-meta" style="margin:6px 0 0;">Respuesta: <?= htmlspecialchars((string) $resendConfirmationMailResult['responseSnippet'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
			<?php endif; ?>
		<?php endif; ?>

		<?php if (!empty($confirmMailCodeResult['attempted'])): ?>
			<p class="<?= !empty($confirmMailCodeResult['saved']) ? 'ok' : 'warn' ?>" style="margin:8px 0 0;">
				Confirmacion correo: <?= htmlspecialchars((string) ($confirmMailCodeResult['reason'] ?? 'unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
				<?= !empty($confirmMailCodeResult['citizenId']) ? ' | Citizen ' . htmlspecialchars((string) $confirmMailCodeResult['citizenId'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
				<?= !empty($confirmMailCodeResult['url']) ? ' | URL ' . htmlspecialchars((string) $confirmMailCodeResult['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
				<?= isset($confirmMailCodeResult['httpStatus']) ? ' | HTTP ' . (int) $confirmMailCodeResult['httpStatus'] : '' ?>
				<?= !empty($confirmMailCodeResult['error']) ? ' | Error cURL: ' . htmlspecialchars((string) $confirmMailCodeResult['error'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
			</p>
			<?php if (!empty($confirmMailCodeResult['responseSnippet'])): ?>
				<p class="section-meta" style="margin:6px 0 0;">Respuesta: <?= htmlspecialchars((string) $confirmMailCodeResult['responseSnippet'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
			<?php endif; ?>
		<?php endif; ?>

		<?php if (!empty($partyStatusCheckResult['attempted'])): ?>
			<p class="<?= !empty($partyStatusCheckResult['needsEmailConfirmation']) ? 'warn' : (!empty($partyStatusCheckResult['saved']) ? 'ok' : 'warn') ?>" style="margin:8px 0 0;">
				Estado partido: <?= htmlspecialchars((string) ($partyStatusCheckResult['reason'] ?? 'unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
				<?= !empty($partyStatusCheckResult['needsEmailConfirmation']) ? ' | Mensaje detectado: You need to confirm your email to join a political party' : '' ?>
				<?= !empty($partyStatusCheckResult['url']) ? ' | URL ' . htmlspecialchars((string) $partyStatusCheckResult['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
				<?= isset($partyStatusCheckResult['httpStatus']) ? ' | HTTP ' . (int) $partyStatusCheckResult['httpStatus'] : '' ?>
				<?= !empty($partyStatusCheckResult['error']) ? ' | Error cURL: ' . htmlspecialchars((string) $partyStatusCheckResult['error'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
			</p>
			<?php if (!empty($partyStatusCheckResult['responseSnippet'])): ?>
				<p class="section-meta" style="margin:6px 0 0;">Respuesta: <?= htmlspecialchars((string) $partyStatusCheckResult['responseSnippet'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
			<?php endif; ?>
		<?php endif; ?>

		<?php if (!empty($partyInspectResult['attempted'])): ?>
			<p class="<?= !empty($partyInspectResult['joinDetected']) ? 'ok' : 'warn' ?>" style="margin:8px 0 0;">
				Inspeccion partido: <?= htmlspecialchars((string) ($partyInspectResult['reason'] ?? 'unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
				<?= !empty($partyInspectResult['partyName']) ? ' | Partido ' . htmlspecialchars((string) $partyInspectResult['partyName'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
				<?= !empty($partyInspectResult['url']) ? ' | URL ' . htmlspecialchars((string) $partyInspectResult['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
				<?= isset($partyInspectResult['httpStatus']) ? ' | HTTP ' . (int) $partyInspectResult['httpStatus'] : '' ?>
				<?= !empty($partyInspectResult['error']) ? ' | Error cURL: ' . htmlspecialchars((string) $partyInspectResult['error'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
			</p>
			<p class="section-meta" style="margin:6px 0 0;">
				Registro detectado: <?= !empty($partyInspectResult['joinDetected']) ? 'SI' : 'NO' ?>
				<?= !empty($partyInspectResult['hasJoinForm']) ? ' | Formulario SI' : ' | Formulario NO' ?>
				<?= !empty($partyInspectResult['hasJoinButton']) ? ' | Boton SI' : ' | Boton NO' ?>
				<?= !empty($partyInspectResult['leaveDetected']) ? ' | Salir detectado SI' : ' | Salir detectado NO' ?>
				<?= !empty($partyInspectResult['joinMethod']) ? ' | Metodo ' . htmlspecialchars((string) $partyInspectResult['joinMethod'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
				<?= !empty($partyInspectResult['joinActionUrl']) ? ' | Action ' . htmlspecialchars((string) $partyInspectResult['joinActionUrl'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
				<?= !empty($partyInspectResult['joinIndicator']) ? ' | Indicador ' . htmlspecialchars((string) $partyInspectResult['joinIndicator'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
			</p>
			<?php
			$partyJoinFields = is_array($partyInspectResult['joinFields'] ?? null) ? (array) $partyInspectResult['joinFields'] : [];
			$partyJoinFieldsEncoded = base64_encode((string) json_encode($partyJoinFields));
			$partyJoinActionUrl = trim((string) ($partyInspectResult['joinActionUrl'] ?? ''));
			$partyJoinMethod = trim((string) ($partyInspectResult['joinMethod'] ?? 'POST'));
			$partyLeaveFields = is_array($partyInspectResult['leaveFields'] ?? null) ? (array) $partyInspectResult['leaveFields'] : [];
			$partyLeaveFieldsEncoded = base64_encode((string) json_encode($partyLeaveFields));
			$partyLeaveActionUrl = trim((string) ($partyInspectResult['leaveActionUrl'] ?? ''));
			$partyLeaveMethod = trim((string) ($partyInspectResult['leaveMethod'] ?? 'POST'));
			?>
			<?php if (!empty($partyInspectResult['joinDetected']) && $partyJoinActionUrl !== ''): ?>
				<form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:8px;padding:10px;border:1px solid #d7e0ee;border-radius:8px;background:#f8fbff;">
					<input type="hidden" name="action" value="party-join-now">
					<input type="hidden" name="party_name" value="<?= htmlspecialchars((string) ($partyInspectResult['partyName'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
					<input type="hidden" name="party_url" value="<?= htmlspecialchars((string) ($partyInspectResult['url'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
					<input type="hidden" name="party_join_action_url" value="<?= htmlspecialchars($partyJoinActionUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
					<input type="hidden" name="party_join_method" value="<?= htmlspecialchars($partyJoinMethod, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
					<input type="hidden" name="party_join_fields_encoded" value="<?= htmlspecialchars($partyJoinFieldsEncoded, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
					<input type="hidden" name="party_join_choice" value="yes">
					<button type="submit" class="train-button">Unirse al Partido</button>
					<span class="section-meta">Se envia POST directo con action JOIN e id detectado.</span>
				</form>
			<?php elseif (!empty($partyInspectResult['leaveDetected']) && $partyLeaveActionUrl !== ''): ?>
				<form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:8px;padding:10px;border:1px solid #f0d5d5;border-radius:8px;background:#fff8f8;">
					<input type="hidden" name="action" value="party-leave-now">
					<input type="hidden" name="party_name" value="<?= htmlspecialchars((string) ($partyInspectResult['partyName'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
					<input type="hidden" name="party_url" value="<?= htmlspecialchars((string) ($partyInspectResult['url'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
					<input type="hidden" name="party_leave_action_url" value="<?= htmlspecialchars($partyLeaveActionUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
					<input type="hidden" name="party_leave_method" value="<?= htmlspecialchars($partyLeaveMethod, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
					<input type="hidden" name="party_leave_fields_encoded" value="<?= htmlspecialchars($partyLeaveFieldsEncoded, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
					<button type="submit" class="train-button" style="background:#b42318;border-color:#8f1c13;">Salir del Partido</button>
					<span class="section-meta">Se envia el formulario LEAVE detectado en partyStatistics.html.</span>
				</form>
			<?php elseif (!empty($partyInspectResult['joinDetected'])): ?>
				<p class="warn" style="margin:6px 0 0;">Se detecto control de union, pero sin action URL utilizable.</p>
			<?php endif; ?>
			<?php if (!empty($partyInspectResult['responseSnippet'])): ?>
				<p class="section-meta" style="margin:6px 0 0;">Respuesta: <?= htmlspecialchars((string) $partyInspectResult['responseSnippet'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
			<?php endif; ?>
			<?php if (!empty($partyInspectResult['responseHtml'])): ?>
				<details style="margin-top:8px;">
					<summary style="cursor:pointer;font-weight:600;color:#243c63;">Ver HTML inspeccionado del partido</summary>
					<pre style="margin-top:8px;max-height:460px;overflow:auto;padding:10px;background:#f7f9fc;border:1px solid #d7e0ee;border-radius:8px;white-space:pre-wrap;word-break:break-word;font-size:12px;line-height:1.35;"><?= htmlspecialchars((string) $partyInspectResult['responseHtml'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></pre>
				</details>
			<?php endif; ?>
		<?php endif; ?>

		<?php if (!empty($partyJoinResult['attempted'])): ?>
			<p class="<?= !empty($partyJoinResult['saved']) ? 'ok' : 'warn' ?>" style="margin:8px 0 0;">
				Unirse al partido: <?= htmlspecialchars((string) ($partyJoinResult['reason'] ?? 'unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
				<?= !empty($partyJoinResult['partyName']) ? ' | Partido ' . htmlspecialchars((string) $partyJoinResult['partyName'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
				<?= !empty($partyJoinResult['url']) ? ' | URL ' . htmlspecialchars((string) $partyJoinResult['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
				<?= !empty($partyJoinResult['joinMethod']) ? ' | Metodo ' . htmlspecialchars((string) $partyJoinResult['joinMethod'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
				<?= !empty($partyJoinResult['joinActionUrl']) ? ' | Action ' . htmlspecialchars((string) $partyJoinResult['joinActionUrl'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
				<?= isset($partyJoinResult['httpStatus']) ? ' | HTTP ' . (int) $partyJoinResult['httpStatus'] : '' ?>
				<?= isset($partyJoinResult['curlErrno']) ? ' | errno ' . (int) $partyJoinResult['curlErrno'] : '' ?>
				<?= isset($partyJoinResult['totalTime']) ? ' | tiempo ' . number_format((float) $partyJoinResult['totalTime'], 3) . 's' : '' ?>
				<?= !empty($partyJoinResult['error']) ? ' | Error cURL: ' . htmlspecialchars((string) $partyJoinResult['error'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
			</p>
			<?php if (($partyJoinResult['reason'] ?? '') === 'party-join-not-confirmed'): ?>
				<p class="section-meta" style="margin:6px 0 0;">No se envio POST. En "Confirmar union" se mantuvo "No, solo preparar".</p>
			<?php endif; ?>
			<?php if (!empty($partyJoinResult['joinReferer']) || !empty($partyJoinResult['requestPayload'])): ?>
				<p class="section-meta" style="margin:6px 0 0;">
					<?= !empty($partyJoinResult['joinReferer']) ? 'Referer: ' . htmlspecialchars((string) $partyJoinResult['joinReferer'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
					<?= !empty($partyJoinResult['joinReferer']) && !empty($partyJoinResult['requestPayload']) ? ' | ' : '' ?>
					<?= !empty($partyJoinResult['requestPayload']) ? 'Payload: ' . htmlspecialchars((string) $partyJoinResult['requestPayload'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
				</p>
			<?php endif; ?>
			<?php if (!empty($partyJoinResult['responseSnippet'])): ?>
				<p class="section-meta" style="margin:6px 0 0;">Respuesta: <?= htmlspecialchars((string) $partyJoinResult['responseSnippet'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
			<?php endif; ?>
			<?php if (!empty($partyJoinResult['attempted']) && (($partyJoinResult['joinChoice'] ?? '') === 'yes' || !empty($partyJoinResult['responseHtml']))): ?>
				<details style="margin-top:8px;">
					<summary style="cursor:pointer;font-weight:600;color:#243c63;">Ver HTML respuesta de unirse al partido</summary>
					<pre style="margin-top:8px;max-height:460px;overflow:auto;padding:10px;background:#f7f9fc;border:1px solid #d7e0ee;border-radius:8px;white-space:pre-wrap;word-break:break-word;font-size:12px;line-height:1.35;"><?= htmlspecialchars((string) (($partyJoinResult['responseHtml'] ?? '') !== '' ? $partyJoinResult['responseHtml'] : '[respuesta vacia]'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></pre>
				</details>
			<?php endif; ?>
		<?php endif; ?>

		<?php if (!empty($partyLeaveResult['attempted'])): ?>
			<p class="<?= !empty($partyLeaveResult['saved']) ? 'ok' : 'warn' ?>" style="margin:8px 0 0;">
				Salir del partido: <?= htmlspecialchars((string) ($partyLeaveResult['reason'] ?? 'unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
				<?= !empty($partyLeaveResult['partyName']) ? ' | Partido ' . htmlspecialchars((string) $partyLeaveResult['partyName'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
				<?= !empty($partyLeaveResult['leaveMethod']) ? ' | Metodo ' . htmlspecialchars((string) $partyLeaveResult['leaveMethod'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
				<?= !empty($partyLeaveResult['leaveActionUrl']) ? ' | Action ' . htmlspecialchars((string) $partyLeaveResult['leaveActionUrl'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
				<?= isset($partyLeaveResult['httpStatus']) ? ' | HTTP ' . (int) $partyLeaveResult['httpStatus'] : '' ?>
				<?= !empty($partyLeaveResult['error']) ? ' | Error cURL: ' . htmlspecialchars((string) $partyLeaveResult['error'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
			</p>
			<?php if (!empty($partyLeaveResult['requestPayload'])): ?>
				<p class="section-meta" style="margin:6px 0 0;">Payload: <?= htmlspecialchars((string) $partyLeaveResult['requestPayload'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
			<?php endif; ?>
			<?php if (!empty($partyLeaveResult['responseSnippet'])): ?>
				<p class="section-meta" style="margin:6px 0 0;">Respuesta: <?= htmlspecialchars((string) $partyLeaveResult['responseSnippet'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
			<?php endif; ?>
			<details style="margin-top:8px;">
				<summary style="cursor:pointer;font-weight:600;color:#243c63;">Ver HTML respuesta de salir del partido</summary>
				<pre style="margin-top:8px;max-height:460px;overflow:auto;padding:10px;background:#f7f9fc;border:1px solid #d7e0ee;border-radius:8px;white-space:pre-wrap;word-break:break-word;font-size:12px;line-height:1.35;"><?= htmlspecialchars((string) (($partyLeaveResult['responseHtml'] ?? '') !== '' ? $partyLeaveResult['responseHtml'] : '[respuesta vacia]'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></pre>
			</details>
		<?php endif; ?>
	</div>

	<div class="section-panel">
		<h2 class="section-title">Articulos</h2>
		<p class="section-meta" style="margin:0;">Ingresa URL del articulo para inspeccionar controles de votar y suscribirse.</p>
		<?php
		$articleUrlInput = trim((string) ($_POST['article_url'] ?? ''));
		if ($articleUrlInput === '') {
			$articleUrlInput = serverUrl('article.html?id=79');
		}
		?>
		<form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:8px;">
			<input type="hidden" name="action" value="article-inspect-url">
			<label for="article_url_input"><strong>URL articulo:</strong></label>
			<input id="article_url_input" type="url" name="article_url" value="<?= htmlspecialchars($articleUrlInput, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="<?= htmlspecialchars(serverUrl('article.html?id=79'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required style="min-width:360px;padding:6px 8px;border:1px solid #c7d5ea;border-radius:8px;">
			<button type="submit" class="train-button">Inspeccionar articulo</button>
		</form>

		<?php if (!empty($articleInspectResult['attempted'])): ?>
			<p class="<?= (!empty($articleInspectResult['voteDetected']) || !empty($articleInspectResult['subscribeDetected'])) ? 'ok' : 'warn' ?>" style="margin:8px 0 0;">
				Inspeccion articulo: <?= htmlspecialchars((string) ($articleInspectResult['reason'] ?? 'unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
				<?= !empty($articleInspectResult['articleTitle']) ? ' | Titulo ' . htmlspecialchars((string) $articleInspectResult['articleTitle'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
				<?= !empty($articleInspectResult['articleId']) ? ' | ID ' . htmlspecialchars((string) $articleInspectResult['articleId'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
				<?= !empty($articleInspectResult['url']) ? ' | URL ' . htmlspecialchars((string) $articleInspectResult['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
				<?= isset($articleInspectResult['httpStatus']) ? ' | HTTP ' . (int) $articleInspectResult['httpStatus'] : '' ?>
				<?= !empty($articleInspectResult['error']) ? ' | Error cURL: ' . htmlspecialchars((string) $articleInspectResult['error'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
			</p>
			<p class="section-meta" style="margin:6px 0 0;">
				Votar detectado: <?= !empty($articleInspectResult['voteDetected']) ? 'SI' : 'NO' ?>
				| Suscribirse detectado: <?= !empty($articleInspectResult['subscribeDetected']) ? 'SI' : 'NO' ?>
				<?= !empty($articleInspectResult['voteActionUrl']) ? ' | Vote URL ' . htmlspecialchars((string) $articleInspectResult['voteActionUrl'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
				<?= !empty($articleInspectResult['subscribeActionUrl']) ? ' | Subscribe URL ' . htmlspecialchars((string) $articleInspectResult['subscribeActionUrl'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
			</p>

			<?php if (!empty($articleInspectResult['articleId']) && !empty($articleInspectResult['url'])): ?>
				<div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
					<?php if (!empty($articleInspectResult['voteDetected'])): ?>
						<form method="post" style="margin:0;display:inline-flex;align-items:center;gap:8px;">
							<input type="hidden" name="action" value="article-vote-now">
							<input type="hidden" name="article_id" value="<?= htmlspecialchars((string) ($articleInspectResult['articleId'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
							<input type="hidden" name="article_url" value="<?= htmlspecialchars((string) ($articleInspectResult['url'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
							<input type="hidden" name="article_vote_action_url" value="<?= htmlspecialchars((string) ($articleInspectResult['voteActionUrl'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
							<button type="submit" class="train-button" style="background:#0d8f49;border-color:#0b7a3e;">Votar +1</button>
						</form>
					<?php endif; ?>

					<?php if (!empty($articleInspectResult['subscribeDetected'])): ?>
						<form method="post" style="margin:0;display:inline-flex;align-items:center;gap:8px;">
							<input type="hidden" name="action" value="article-subscribe-now">
							<input type="hidden" name="article_id" value="<?= htmlspecialchars((string) ($articleInspectResult['articleId'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
							<input type="hidden" name="article_url" value="<?= htmlspecialchars((string) ($articleInspectResult['url'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
							<input type="hidden" name="article_subscribe_action_url" value="<?= htmlspecialchars((string) ($articleInspectResult['subscribeActionUrl'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
							<button type="submit" class="train-button" style="background:#925f00;border-color:#7c4f00;">Suscribirme</button>
						</form>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if (!empty($articleInspectResult['responseSnippet'])): ?>
				<p class="section-meta" style="margin:6px 0 0;">Respuesta: <?= htmlspecialchars((string) $articleInspectResult['responseSnippet'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
			<?php endif; ?>
		<?php endif; ?>

		<?php if (!empty($articleVoteResult['attempted'])): ?>
			<p class="<?= !empty($articleVoteResult['saved']) ? 'ok' : 'warn' ?>" style="margin:8px 0 0;">
				Voto articulo: <?= htmlspecialchars((string) ($articleVoteResult['reason'] ?? 'unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
				<?= !empty($articleVoteResult['articleId']) ? ' | ID ' . htmlspecialchars((string) $articleVoteResult['articleId'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
				<?= !empty($articleVoteResult['url']) ? ' | URL ' . htmlspecialchars((string) $articleVoteResult['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
				<?= isset($articleVoteResult['httpStatus']) ? ' | HTTP ' . (int) $articleVoteResult['httpStatus'] : '' ?>
				<?= !empty($articleVoteResult['error']) ? ' | Error cURL: ' . htmlspecialchars((string) $articleVoteResult['error'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
			</p>
			<?php if (!empty($articleVoteResult['responseSnippet'])): ?>
				<p class="section-meta" style="margin:6px 0 0;">Respuesta voto: <?= htmlspecialchars((string) $articleVoteResult['responseSnippet'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
			<?php endif; ?>
		<?php endif; ?>

		<?php if (!empty($articleSubscribeResult['attempted'])): ?>
			<p class="<?= !empty($articleSubscribeResult['saved']) ? 'ok' : 'warn' ?>" style="margin:8px 0 0;">
				Suscripcion articulo: <?= htmlspecialchars((string) ($articleSubscribeResult['reason'] ?? 'unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
				<?= !empty($articleSubscribeResult['articleId']) ? ' | ID ' . htmlspecialchars((string) $articleSubscribeResult['articleId'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
				<?= !empty($articleSubscribeResult['url']) ? ' | URL ' . htmlspecialchars((string) $articleSubscribeResult['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
				<?= isset($articleSubscribeResult['httpStatus']) ? ' | HTTP ' . (int) $articleSubscribeResult['httpStatus'] : '' ?>
				<?= !empty($articleSubscribeResult['error']) ? ' | Error cURL: ' . htmlspecialchars((string) $articleSubscribeResult['error'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
			</p>
			<?php if (!empty($articleSubscribeResult['responseSnippet'])): ?>
				<p class="section-meta" style="margin:6px 0 0;">Respuesta suscripcion: <?= htmlspecialchars((string) $articleSubscribeResult['responseSnippet'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
			<?php endif; ?>
		<?php endif; ?>
	</div>

	<div class="section-panel">
		<h2 class="section-title">Elecciones (Inspect)</h2>
		<p class="section-meta" style="margin:0;">Inspecciona el HTML de elecciones para identificar el formulario de candidatura.</p>
		<?php
		$electionsUrlInput = trim((string) ($_POST['elections_url'] ?? ''));
		if ($electionsUrlInput === '') {
			$electionsUrlInput = serverUrl('elections.html?electionType=CONGRESS');
		}
		?>
		<form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:8px;">
			<input type="hidden" name="action" value="elections-inspect-url">
			<label for="elections_url_input"><strong>URL elecciones:</strong></label>
			<input id="elections_url_input" type="url" name="elections_url" value="<?= htmlspecialchars($electionsUrlInput, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required style="min-width:380px;padding:6px 8px;border:1px solid #c7d5ea;border-radius:8px;">
			<button type="submit" class="train-button">Inspeccionar elecciones</button>
		</form>

		<?php if (!empty($electionsInspectResult['attempted'])): ?>
			<p class="<?= !empty($electionsInspectResult['saved']) ? 'ok' : 'warn' ?>" style="margin:8px 0 0;">
				Inspeccion elecciones: <?= htmlspecialchars((string) ($electionsInspectResult['reason'] ?? 'unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
				<?= !empty($electionsInspectResult['pageTitle']) ? ' | Titulo ' . htmlspecialchars((string) $electionsInspectResult['pageTitle'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
				<?= !empty($electionsInspectResult['url']) ? ' | URL ' . htmlspecialchars((string) $electionsInspectResult['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
				<?= isset($electionsInspectResult['httpStatus']) ? ' | HTTP ' . (int) $electionsInspectResult['httpStatus'] : '' ?>
				<?= isset($electionsInspectResult['options']) && is_array($electionsInspectResult['options']) ? ' | Opciones ' . count((array) $electionsInspectResult['options']) : '' ?>
				<?= !empty($electionsInspectResult['error']) ? ' | Error cURL: ' . htmlspecialchars((string) $electionsInspectResult['error'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
			</p>
			<?php if (!empty($electionsInspectResult['options']) && is_array($electionsInspectResult['options'])): ?>
				<div style="margin-top:8px;padding:10px;border:1px solid #d7e0ee;border-radius:8px;background:#f8fbff;">
					<p style="margin:0 0 6px;"><strong>Opciones detectadas (forms/links):</strong></p>
					<ul style="margin:0;padding-left:18px;display:flex;flex-direction:column;gap:6px;">
						<?php foreach ((array) ($electionsInspectResult['options'] ?? []) as $inspectOption): ?>
							<?php
							$optType = trim((string) ($inspectOption['type'] ?? ''));
							$optLabel = trim((string) ($inspectOption['label'] ?? ''));
							$optMethod = trim((string) ($inspectOption['method'] ?? ''));
							$optAction = trim((string) ($inspectOption['action'] ?? ''));
							$optFields = is_array($inspectOption['fields'] ?? null) ? (array) $inspectOption['fields'] : [];
							?>
							<li>
								<?= htmlspecialchars($optType !== '' ? strtoupper($optType) : 'OPTION', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
								<?= $optLabel !== '' ? ' | ' . htmlspecialchars($optLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
								<?= $optMethod !== '' ? ' | ' . htmlspecialchars($optMethod, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
								<?= $optAction !== '' ? ' | ' . htmlspecialchars($optAction, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
								<?= !empty($optFields) ? ' | fields: ' . htmlspecialchars(implode(', ', $optFields), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>
			<?php if (!empty($electionsInspectResult['responseSnippet'])): ?>
				<p class="section-meta" style="margin:6px 0 0;">Respuesta: <?= htmlspecialchars((string) $electionsInspectResult['responseSnippet'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
			<?php endif; ?>
			<?php
			$candidateActionUrl = trim((string) ($electionsInspectResult['candidateActionUrl'] ?? ''));
			if ($candidateActionUrl === '' && !empty($electionsInspectResult['url'])) {
				$candidateActionUrl = resolveUrl((string) $electionsInspectResult['url'], 'congressElectionsCandidate');
			}
			$candidatePresentationInput = trim((string) ($_POST['elections_presentation'] ?? 'http://'));
			if ($candidatePresentationInput === '') {
				$candidatePresentationInput = 'http://';
			}
			?>
			<?php if ($candidateActionUrl !== ''): ?>
				<form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:10px;padding:10px;border:1px solid #d7e0ee;border-radius:8px;background:#f8fbff;">
					<input type="hidden" name="action" value="elections-congress-candidate-now">
					<input type="hidden" name="elections_url" value="<?= htmlspecialchars((string) ($electionsInspectResult['url'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
					<input type="hidden" name="elections_candidate_action_url" value="<?= htmlspecialchars($candidateActionUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
					<label for="elections_presentation_input"><strong>Enlace presentacion:</strong></label>
					<input id="elections_presentation_input" type="text" name="elections_presentation" value="<?= htmlspecialchars($candidatePresentationInput, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required style="min-width:320px;padding:6px 8px;border:1px solid #c7d5ea;border-radius:8px;">
					<button type="submit" class="train-button" style="background:#0d8f49;border-color:#0b7a3e;">Postular candidatura al Congreso</button>
				</form>
			<?php endif; ?>
			<?php if (!empty($electionsInspectResult['responseHtml'])): ?>
				<details style="margin-top:8px;">
					<summary style="cursor:pointer;font-weight:600;color:#243c63;">Ver HTML inspeccionado de elecciones</summary>
					<pre style="margin-top:8px;max-height:460px;overflow:auto;padding:10px;background:#f7f9fc;border:1px solid #d7e0ee;border-radius:8px;white-space:pre-wrap;word-break:break-word;font-size:12px;line-height:1.35;"><?= htmlspecialchars((string) $electionsInspectResult['responseHtml'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></pre>
				</details>
			<?php endif; ?>
		<?php endif; ?>

		<?php if (!empty($electionsCandidateResult['attempted'])): ?>
			<p class="<?= !empty($electionsCandidateResult['saved']) ? 'ok' : 'warn' ?>" style="margin:8px 0 0;">
				Candidatura Congreso: <?= htmlspecialchars((string) ($electionsCandidateResult['reason'] ?? 'unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
				<?= !empty($electionsCandidateResult['url']) ? ' | URL ' . htmlspecialchars((string) $electionsCandidateResult['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
				<?= isset($electionsCandidateResult['httpStatus']) ? ' | HTTP ' . (int) $electionsCandidateResult['httpStatus'] : '' ?>
				<?= !empty($electionsCandidateResult['error']) ? ' | Error: ' . htmlspecialchars((string) $electionsCandidateResult['error'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
			</p>
			<?php if (!empty($electionsCandidateResult['requestPayload'])): ?>
				<p class="section-meta" style="margin:6px 0 0;">Payload: <?= htmlspecialchars((string) $electionsCandidateResult['requestPayload'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
			<?php endif; ?>
			<?php if (!empty($electionsCandidateResult['responseSnippet'])): ?>
				<p class="section-meta" style="margin:6px 0 0;">Respuesta: <?= htmlspecialchars((string) $electionsCandidateResult['responseSnippet'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
			<?php endif; ?>
			<details style="margin-top:8px;">
				<summary style="cursor:pointer;font-weight:600;color:#243c63;">Ver respuesta candidatura (raw)</summary>
				<pre style="margin-top:8px;max-height:460px;overflow:auto;padding:10px;background:#f7f9fc;border:1px solid #d7e0ee;border-radius:8px;white-space:pre-wrap;word-break:break-word;font-size:12px;line-height:1.35;"><?= htmlspecialchars((string) (($electionsCandidateResult['responseHtml'] ?? '') !== '' ? $electionsCandidateResult['responseHtml'] : '[respuesta vacia]'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></pre>
			</details>
		<?php endif; ?>
	</div>

	<div class="section-panel">
		<h2 class="section-title">Unidad Militar (Inspect)</h2>
		<p class="section-meta" style="margin:0;">Inspecciona la unidad militar para listar acciones disponibles y ver su HTML.</p>
		<?php
		$militaryUnitUrlInput = trim((string) ($_POST['military_unit_url'] ?? ''));
		if ($militaryUnitUrlInput === '') {
			$militaryUnitUrlInput = serverUrl('militaryUnit.html?id=37');
		}
		?>
		<form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:8px;">
			<input type="hidden" name="action" value="military-unit-inspect-url">
			<label for="military_unit_url_input"><strong>URL unidad:</strong></label>
			<input id="military_unit_url_input" type="url" name="military_unit_url" value="<?= htmlspecialchars($militaryUnitUrlInput, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required style="min-width:380px;padding:6px 8px;border:1px solid #c7d5ea;border-radius:8px;">
			<button type="submit" class="train-button">Inspeccionar unidad militar</button>
		</form>

		<?php if (!empty($militaryUnitInspectResult['attempted'])): ?>
			<p class="<?= !empty($militaryUnitInspectResult['saved']) ? 'ok' : 'warn' ?>" style="margin:8px 0 0;">
				Inspeccion unidad militar: <?= htmlspecialchars((string) ($militaryUnitInspectResult['reason'] ?? 'unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
				<?= !empty($militaryUnitInspectResult['unitName']) ? ' | Unidad ' . htmlspecialchars((string) $militaryUnitInspectResult['unitName'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
				<?= !empty($militaryUnitInspectResult['url']) ? ' | URL ' . htmlspecialchars((string) $militaryUnitInspectResult['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
				<?= isset($militaryUnitInspectResult['httpStatus']) ? ' | HTTP ' . (int) $militaryUnitInspectResult['httpStatus'] : '' ?>
				<?= isset($militaryUnitInspectResult['options']) && is_array($militaryUnitInspectResult['options']) ? ' | Opciones ' . count((array) $militaryUnitInspectResult['options']) : '' ?>
				<?= !empty($militaryUnitInspectResult['error']) ? ' | Error cURL: ' . htmlspecialchars((string) $militaryUnitInspectResult['error'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
			</p>
			<?php if (!empty($militaryUnitInspectResult['options']) && is_array($militaryUnitInspectResult['options'])): ?>
				<div style="margin-top:8px;padding:10px;border:1px solid #d7e0ee;border-radius:8px;background:#f8fbff;">
					<p style="margin:0 0 6px;"><strong>Opciones detectadas (forms/links):</strong></p>
					<ul style="margin:0;padding-left:18px;display:flex;flex-direction:column;gap:6px;">
						<?php foreach ((array) ($militaryUnitInspectResult['options'] ?? []) as $inspectOption): ?>
							<?php
							$optType = trim((string) ($inspectOption['type'] ?? ''));
							$optLabel = trim((string) ($inspectOption['label'] ?? ''));
							$optMethod = trim((string) ($inspectOption['method'] ?? ''));
							$optAction = trim((string) ($inspectOption['action'] ?? ''));
							$optFields = is_array($inspectOption['fields'] ?? null) ? (array) $inspectOption['fields'] : [];
							?>
							<li>
								<?= htmlspecialchars($optType !== '' ? strtoupper($optType) : 'OPTION', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
								<?= $optLabel !== '' ? ' | ' . htmlspecialchars($optLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
								<?= $optMethod !== '' ? ' | ' . htmlspecialchars($optMethod, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
								<?= $optAction !== '' ? ' | ' . htmlspecialchars($optAction, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
								<?= !empty($optFields) ? ' | fields: ' . htmlspecialchars(implode(', ', $optFields), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>
			<?php if (!empty($militaryUnitInspectResult['responseSnippet'])): ?>
				<p class="section-meta" style="margin:6px 0 0;">Respuesta: <?= htmlspecialchars((string) $militaryUnitInspectResult['responseSnippet'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
			<?php endif; ?>
			<?php
			$militaryApplyDetected = !empty($militaryUnitInspectResult['applyDetected']);
			$militaryApplyActionUrl = trim((string) ($militaryUnitInspectResult['applyActionUrl'] ?? ''));
			$militaryApplyMethod = trim((string) ($militaryUnitInspectResult['applyMethod'] ?? 'POST'));
			$militaryApplyFields = is_array($militaryUnitInspectResult['applyFields'] ?? null) ? (array) $militaryUnitInspectResult['applyFields'] : [];
			$militaryApplyFieldsEncoded = base64_encode((string) json_encode($militaryApplyFields));
			$militaryApplyMessageInput = trim((string) ($_POST['military_unit_apply_message'] ?? (string) ($militaryUnitInspectResult['applyDefaultMessage'] ?? 'Comparte tu motivacion para unirte a esta MU.')));
			if ($militaryApplyMessageInput === '') {
				$militaryApplyMessageInput = 'Comparte tu motivacion para unirte a esta MU.';
			}
			?>
			<?php if ($militaryApplyDetected && $militaryApplyActionUrl !== ''): ?>
				<form method="post" style="display:flex;gap:8px;align-items:flex-start;flex-wrap:wrap;margin-top:10px;padding:10px;border:1px solid #d7e0ee;border-radius:8px;background:#f8fbff;">
					<input type="hidden" name="action" value="military-unit-apply-now">
					<input type="hidden" name="military_unit_url" value="<?= htmlspecialchars((string) ($militaryUnitInspectResult['url'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
					<input type="hidden" name="military_unit_apply_action_url" value="<?= htmlspecialchars($militaryApplyActionUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
					<input type="hidden" name="military_unit_apply_method" value="<?= htmlspecialchars($militaryApplyMethod, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
					<input type="hidden" name="military_unit_apply_fields_encoded" value="<?= htmlspecialchars($militaryApplyFieldsEncoded, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
					<label for="military_unit_apply_message_input"><strong>Motivacion:</strong></label>
					<textarea id="military_unit_apply_message_input" name="military_unit_apply_message" rows="3" style="min-width:360px;max-width:100%;padding:6px 8px;border:1px solid #c7d5ea;border-radius:8px;"><?= htmlspecialchars($militaryApplyMessageInput, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
					<button type="submit" class="train-button" style="background:#0d8f49;border-color:#0b7a3e;">Postularme a la Unidad Militar</button>
				</form>
			<?php endif; ?>
			<?php if (!empty($militaryUnitInspectResult['responseHtml'])): ?>
				<details style="margin-top:8px;">
					<summary style="cursor:pointer;font-weight:600;color:#243c63;">Ver HTML inspeccionado de unidad militar</summary>
					<pre style="margin-top:8px;max-height:460px;overflow:auto;padding:10px;background:#f7f9fc;border:1px solid #d7e0ee;border-radius:8px;white-space:pre-wrap;word-break:break-word;font-size:12px;line-height:1.35;"><?= htmlspecialchars((string) $militaryUnitInspectResult['responseHtml'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></pre>
				</details>
			<?php endif; ?>
		<?php endif; ?>

		<?php if (!empty($militaryUnitApplyResult['attempted'])): ?>
			<p class="<?= !empty($militaryUnitApplyResult['saved']) ? 'ok' : 'warn' ?>" style="margin:8px 0 0;">
				Postulacion MU: <?= htmlspecialchars((string) ($militaryUnitApplyResult['reason'] ?? 'unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
				<?= !empty($militaryUnitApplyResult['unitId']) ? ' | ID MU ' . htmlspecialchars((string) $militaryUnitApplyResult['unitId'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
				<?= !empty($militaryUnitApplyResult['url']) ? ' | URL ' . htmlspecialchars((string) $militaryUnitApplyResult['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
				<?= isset($militaryUnitApplyResult['httpStatus']) ? ' | HTTP ' . (int) $militaryUnitApplyResult['httpStatus'] : '' ?>
				<?= !empty($militaryUnitApplyResult['error']) ? ' | Error: ' . htmlspecialchars((string) $militaryUnitApplyResult['error'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
			</p>
			<?php if (!empty($militaryUnitApplyResult['requestPayload'])): ?>
				<p class="section-meta" style="margin:6px 0 0;">Payload: <?= htmlspecialchars((string) $militaryUnitApplyResult['requestPayload'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
			<?php endif; ?>
			<?php if (!empty($militaryUnitApplyResult['responseSnippet'])): ?>
				<p class="section-meta" style="margin:6px 0 0;">Respuesta: <?= htmlspecialchars((string) $militaryUnitApplyResult['responseSnippet'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
			<?php endif; ?>
			<details style="margin-top:8px;">
				<summary style="cursor:pointer;font-weight:600;color:#243c63;">Ver respuesta postulacion MU (raw)</summary>
				<pre style="margin-top:8px;max-height:460px;overflow:auto;padding:10px;background:#f7f9fc;border:1px solid #d7e0ee;border-radius:8px;white-space:pre-wrap;word-break:break-word;font-size:12px;line-height:1.35;"><?= htmlspecialchars((string) (($militaryUnitApplyResult['responseHtml'] ?? '') !== '' ? $militaryUnitApplyResult['responseHtml'] : '[respuesta vacia]'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></pre>
			</details>
		<?php endif; ?>
	</div>

	<div class="section-panel">
		<h2 class="section-title">Monedas (Storage MONEY)</h2>
		<p class="section-meta" style="margin:0;">
			Fuente: <?= htmlspecialchars((string) ($storageMoneyResult['url'] ?? $storageMoneyUrl), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
			<?= isset($storageMoneyResult['httpStatus']) ? ' | HTTP ' . (int) $storageMoneyResult['httpStatus'] : '' ?>
			<?= isset($storageMoneyResult['accountsCount']) ? ' | Cuentas ' . (int) $storageMoneyResult['accountsCount'] : '' ?>
			<?= isset($storageMoneyResult['bodyLength']) ? ' | HTML ' . number_format((int) $storageMoneyResult['bodyLength']) . ' bytes' : '' ?>
		</p>

		<?php if (empty($storageMoneyResult['attempted'])): ?>
			<p class="warn" style="margin:8px 0 0;">No se intento consultar monedas porque la sesion no quedo autenticada.</p>
		<?php elseif (empty($storageMoneyResult['saved'])): ?>
			<p class="warn" style="margin:8px 0 0;">No se pudieron cargar monedas (<?= htmlspecialchars((string) ($storageMoneyResult['reason'] ?? 'unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>).</p>
		<?php elseif (empty($storageMoneyResult['accounts']) || !is_array($storageMoneyResult['accounts'])): ?>
			<p class="warn" style="margin:8px 0 0;">No se detectaron cuentas de moneda en el HTML recibido.</p>
		<?php else: ?>
			<div style="margin-top:10px;padding:10px;border:1px solid #d7e0ee;border-radius:8px;background:#f8fbff;">
				<p style="margin:0 0 6px;"><strong>Monedas disponibles:</strong></p>
				<ul style="margin:0;padding-left:18px;display:flex;flex-direction:column;gap:6px;">
					<?php foreach ((array) ($storageMoneyResult['accounts'] ?? []) as $moneyItem): ?>
						<?php
						$moneyAmount = trim((string) ($moneyItem['amount'] ?? ''));
						$moneyAmountFormatted = formatAmountWithThousands($moneyAmount, 2);
						$moneyCurrency = trim((string) ($moneyItem['currency'] ?? ''));
						$moneyCountry = trim((string) ($moneyItem['country'] ?? ''));
						if ($moneyAmount === '' || $moneyCurrency === '') {
							continue;
						}
						?>
						<li>
							<strong><?= htmlspecialchars($moneyAmountFormatted, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
							<?= htmlspecialchars($moneyCurrency, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
							<?php if ($moneyCountry !== ''): ?><span class="section-meta" style="margin-left:6px;">(<?= htmlspecialchars($moneyCountry, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>)</span><?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>
	</div>

	<div class="section-panel">
		<h2 class="section-title">Equipos (Storage EQUIPMENT)</h2>
		<p class="section-meta" style="margin:0;">Consulta manual para listar equipo equipado y adicional (incluye inventory list), con opcion de subasta.</p>
		<div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
			<form method="post" style="margin:0;display:inline-flex;align-items:center;">
				<input type="hidden" name="action" value="equipment-load">
				<button type="submit" class="train-button">Consultar equipos</button>
			</form>
		</div>

		<?php if (!empty($equipmentSellResult['attempted'])): ?>
			<p class="<?= !empty($equipmentSellResult['saved']) ? 'ok' : 'warn' ?>" style="margin:8px 0 0;">
				Subasta equipo #<?= htmlspecialchars((string) ($equipmentSellResult['itemId'] ?? '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>:
				<?= htmlspecialchars((string) ($equipmentSellResult['reason'] ?? 'unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
				<?= isset($equipmentSellResult['httpStatus']) ? ' | HTTP ' . (int) $equipmentSellResult['httpStatus'] : '' ?>
				<?= trim((string) ($equipmentSellResult['price'] ?? '')) !== '' ? ' | Precio ' . htmlspecialchars((string) $equipmentSellResult['price'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
				<?= trim((string) ($equipmentSellResult['length'] ?? '')) !== '' ? ' | Horas ' . htmlspecialchars((string) $equipmentSellResult['length'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
			</p>
			<?php if (trim((string) ($equipmentSellResult['responseSnippet'] ?? '')) !== ''): ?>
				<p class="section-meta" style="margin:6px 0 0;">Respuesta: <?= htmlspecialchars((string) $equipmentSellResult['responseSnippet'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
			<?php endif; ?>
		<?php endif; ?>

		<?php if (!empty($storageEquipmentResult['attempted'])): ?>
			<p class="section-meta" style="margin:8px 0 0;">
				Fuente: <?= htmlspecialchars((string) ($storageEquipmentResult['url'] ?? $storageEquipmentUrl), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
				<?= isset($storageEquipmentResult['httpStatus']) ? ' | HTTP ' . (int) $storageEquipmentResult['httpStatus'] : '' ?>
				<?= !empty($storageEquipmentResult['inventoryUrl']) ? ' | Inventory ' . htmlspecialchars((string) ($storageEquipmentResult['inventoryUrl'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
				<?= isset($storageEquipmentResult['inventoryHttpStatus']) ? ' | Inventory HTTP ' . (int) $storageEquipmentResult['inventoryHttpStatus'] : '' ?>
				<?= isset($storageEquipmentResult['equippedCount']) ? ' | Equipados ' . (int) $storageEquipmentResult['equippedCount'] : '' ?>
				<?= isset($storageEquipmentResult['storageCount']) ? ' | Adicionales ' . (int) $storageEquipmentResult['storageCount'] : '' ?>
				<?= isset($storageEquipmentResult['bodyLength']) ? ' | HTML ' . number_format((int) $storageEquipmentResult['bodyLength']) . ' bytes' : '' ?>
			</p>

			<?php if (empty($storageEquipmentResult['saved'])): ?>
				<p class="warn" style="margin:8px 0 0;">No se pudieron cargar equipos (<?= htmlspecialchars((string) ($storageEquipmentResult['reason'] ?? 'unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>).</p>
			<?php else: ?>
				<?php $renderEquipmentCards = static function (array $items, string $emptyMessage): void { ?>
					<?php if (empty($items)): ?>
						<p class="warn" style="margin:8px 0 0;"><?= htmlspecialchars($emptyMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
					<?php else: ?>
						<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:10px;margin-top:10px;">
							<?php foreach ($items as $eqItem): ?>
								<?php
								$itemId = trim((string) ($eqItem['id'] ?? ''));
								$itemName = trim((string) ($eqItem['name'] ?? ''));
								$itemType = trim((string) ($eqItem['type'] ?? ''));
								$itemQuality = trim((string) ($eqItem['quality'] ?? ''));
								$itemSet = trim((string) ($eqItem['set'] ?? ''));
								$itemImageClassRaw = trim((string) ($eqItem['imageClass'] ?? ''));
								$itemImageClass = preg_replace('/[^a-zA-Z0-9_-]/', '', $itemImageClassRaw);
								$itemQualityClassRaw = strtolower(trim((string) ($eqItem['qualityClass'] ?? '')));
								$itemQualityClass = preg_match('/^q\d+$/', $itemQualityClassRaw) === 1 ? $itemQualityClassRaw : 'q1';
								$itemDetailUrl = trim((string) ($eqItem['detailUrl'] ?? ''));
								$itemAttributes = is_array($eqItem['attributes'] ?? null) ? (array) $eqItem['attributes'] : [];
								$itemCanAuction = !empty($eqItem['canAuction']);
								$itemAuctionId = trim((string) ($eqItem['auctionItemId'] ?? ''));
								$itemAuctionPrice = trim((string) ($eqItem['auctionPrice'] ?? ''));
								$itemAuctionLength = trim((string) ($eqItem['auctionLength'] ?? ''));
								?>
								<div style="border:1px solid #d7d7d7;border-radius:8px;padding:10px;background:#fff;display:flex;flex-direction:column;gap:8px;">
									<div style="display:flex;gap:10px;align-items:flex-start;">
										<div style="min-width:64px;width:64px;height:64px;display:flex;align-items:center;justify-content:center;border-radius:8px;background:#f5f7fb;border:1px solid #e0e6f2;">
											<?php if ($itemImageClass !== ''): ?>
												<div class="equipmentBack <?= htmlspecialchars($itemQualityClass, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" style="float:none;margin:0;"><div class="equipmentImage <?= htmlspecialchars($itemImageClass, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></div></div>
											<?php else: ?>
												<span class="section-meta" style="text-align:center;">Sin imagen</span>
											<?php endif; ?>
										</div>
										<div style="flex:1;min-width:0;">
											<p style="margin:0 0 4px;"><strong><?= htmlspecialchars($itemName !== '' ? $itemName : ('Equipo #' . ($itemId !== '' ? $itemId : '?')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></p>
											<p style="margin:0 0 4px;"><strong>Tipo:</strong> <?= htmlspecialchars($itemType !== '' ? $itemType : '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
											<p style="margin:0 0 4px;"><strong>Calidad:</strong> <?= htmlspecialchars($itemQuality !== '' ? $itemQuality : '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
											<?php if ($itemSet !== ''): ?><p style="margin:0 0 4px;"><strong>Set:</strong> <?= htmlspecialchars($itemSet, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>
											<?php if ($itemId !== ''): ?><p style="margin:0;" class="section-meta">ID: #<?= htmlspecialchars($itemId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>
										</div>
									</div>

									<?php if (!empty($itemAttributes)): ?>
										<div style="padding:8px;border:1px solid #e9edf5;border-radius:8px;background:#fafcff;">
											<p style="margin:0 0 6px;"><strong>Caracteristicas:</strong></p>
											<ul style="margin:0;padding-left:18px;display:flex;flex-direction:column;gap:4px;">
												<?php foreach ($itemAttributes as $attributeText): ?>
													<li><?= htmlspecialchars((string) $attributeText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
												<?php endforeach; ?>
											</ul>
										</div>
									<?php endif; ?>

									<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
										<?php if ($itemDetailUrl !== ''): ?>
											<a href="<?= htmlspecialchars($itemDetailUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" target="_blank" rel="noopener" class="train-button" style="text-decoration:none;display:inline-flex;align-items:center;">Ver equipo</a>
										<?php endif; ?>
										<?php if ($itemCanAuction): ?>
											<form method="post" style="margin:0;display:inline-flex;align-items:center;">
												<input type="hidden" name="action" value="equipment-sell-now">
												<input type="hidden" name="equipment_auction_item_id" value="<?= htmlspecialchars($itemAuctionId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
												<input type="hidden" name="equipment_auction_price" value="<?= htmlspecialchars($itemAuctionPrice, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
												<input type="hidden" name="equipment_auction_length" value="<?= htmlspecialchars($itemAuctionLength, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
												<input type="hidden" name="equipment_page" value="1">
												<button type="submit" class="train-button" style="background:#a85600;border-color:#8a4600;">Poner en subasta</button>
											</form>
										<?php endif; ?>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				<?php }; ?>

				<h3 style="margin:12px 0 6px;">Equipados</h3>
				<?php $renderEquipmentCards((array) ($storageEquipmentResult['equipped'] ?? []), 'No se detectaron equipos equipados.'); ?>

				<h3 style="margin:14px 0 6px;">Adicionales en storage</h3>
				<?php $renderEquipmentCards((array) ($storageEquipmentResult['storage'] ?? []), 'No se detectaron equipos adicionales en storage.'); ?>
			<?php endif; ?>
		<?php else: ?>
			<p class="warn" style="margin:8px 0 0;">Todavia no se consultaron equipos. Usa el boton "Consultar equipos".</p>
		<?php endif; ?>
	</div>

	<div class="section-panel">
		<h2 class="section-title">Mercado de Subastas</h2>
		<p class="section-meta" style="margin:0;">Consulta ofertas activas y permite ofertar desde el panel.</p>
		<div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
			<form method="post" style="margin:0;display:inline-flex;align-items:center;">
				<input type="hidden" name="action" value="auctions-load">
				<button type="submit" class="train-button">Consultar mercado de subastas</button>
			</form>
		</div>

		<?php if (!empty($auctionBidResult['attempted'])): ?>
			<p class="<?= !empty($auctionBidResult['saved']) ? 'ok' : 'warn' ?>" style="margin:8px 0 0;">
				Oferta subasta #<?= htmlspecialchars((string) ($auctionBidResult['auctionId'] ?? '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>:
				<?= htmlspecialchars((string) ($auctionBidResult['reason'] ?? 'unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
				<?= isset($auctionBidResult['httpStatus']) ? ' | HTTP ' . (int) $auctionBidResult['httpStatus'] : '' ?>
				<?= trim((string) ($auctionBidResult['price'] ?? '')) !== '' ? ' | Precio ' . htmlspecialchars((string) $auctionBidResult['price'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
				<?= !empty($auctionBidResult['error']) ? ' | Error cURL: ' . htmlspecialchars((string) $auctionBidResult['error'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
			</p>
			<?php if (trim((string) ($auctionBidResult['responseSnippet'] ?? '')) !== ''): ?>
				<p class="section-meta" style="margin:6px 0 0;">Respuesta: <?= htmlspecialchars((string) $auctionBidResult['responseSnippet'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
			<?php endif; ?>
		<?php endif; ?>

		<?php if (!empty($auctionMarketResult['attempted'])): ?>
			<p class="section-meta" style="margin:8px 0 0;">
				Fuente: <?= htmlspecialchars((string) ($auctionMarketResult['url'] ?? $auctionsUrl), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
				<?= isset($auctionMarketResult['httpStatus']) ? ' | HTTP ' . (int) $auctionMarketResult['httpStatus'] : '' ?>
				<?= isset($auctionMarketResult['itemsCount']) ? ' | Ofertas ' . (int) $auctionMarketResult['itemsCount'] : '' ?>
				<?= isset($auctionMarketResult['bodyLength']) ? ' | HTML ' . number_format((int) $auctionMarketResult['bodyLength']) . ' bytes' : '' ?>
			</p>

			<?php if (empty($auctionMarketResult['saved'])): ?>
				<p class="warn" style="margin:8px 0 0;">No se pudieron cargar subastas (<?= htmlspecialchars((string) ($auctionMarketResult['reason'] ?? 'unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>).</p>
			<?php elseif (empty($auctionMarketResult['offers']) || !is_array($auctionMarketResult['offers'])): ?>
				<p class="warn" style="margin:8px 0 0;">No se detectaron ofertas activas para ofertar.</p>
			<?php else: ?>
				<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:10px;margin-top:10px;">
					<?php foreach ((array) ($auctionMarketResult['offers'] ?? []) as $auctionOffer): ?>
						<?php
						$auctionId = trim((string) ($auctionOffer['auctionId'] ?? ''));
						$bidPrice = trim((string) ($auctionOffer['bidPrice'] ?? ''));
						$minimalOutbid = trim((string) ($auctionOffer['minimalOutbid'] ?? ''));
						$currentPrice = trim((string) ($auctionOffer['currentPrice'] ?? ''));
						$seller = trim((string) ($auctionOffer['seller'] ?? ''));
						$item = trim((string) ($auctionOffer['item'] ?? ''));
						$description = trim((string) ($auctionOffer['description'] ?? ''));
						if ($auctionId === '') {
							continue;
						}
						?>
						<div style="border:1px solid #d7d7d7;border-radius:8px;padding:10px;background:#fff;display:flex;flex-direction:column;gap:8px;">
							<p style="margin:0 0 4px;"><strong>Subasta #<?= htmlspecialchars($auctionId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></p>
							<?php if ($item !== ''): ?><p style="margin:0 0 4px;"><strong>Item:</strong> <?= htmlspecialchars($item, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>
							<?php if ($description !== ''): ?><p style="margin:0 0 4px;"><?= htmlspecialchars($description, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>
							<p style="margin:0 0 4px;">
								<strong>Precio sugerido:</strong> <?= htmlspecialchars($bidPrice !== '' ? $bidPrice : '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
								<?= $minimalOutbid !== '' ? ' | Min outbid ' . htmlspecialchars($minimalOutbid, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
								<?= $currentPrice !== '' ? ' | Actual ' . htmlspecialchars($currentPrice, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
							</p>
							<?php if ($seller !== ''): ?><p style="margin:0 0 6px;"><strong>Vendedor:</strong> <?= htmlspecialchars($seller, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>

							<form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:0;">
								<input type="hidden" name="action" value="auction-bid-now">
								<input type="hidden" name="auction_market_url" value="<?= htmlspecialchars((string) ($auctionMarketResult['url'] ?? $auctionsUrl), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
								<input type="hidden" name="auction_id" value="<?= htmlspecialchars($auctionId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
								<label for="auction_bid_price_<?= htmlspecialchars($auctionId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="section-meta">Precio:</label>
								<input id="auction_bid_price_<?= htmlspecialchars($auctionId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" type="text" name="auction_bid_price" value="<?= htmlspecialchars($bidPrice !== '' ? $bidPrice : ($minimalOutbid !== '' ? $minimalOutbid : '0.01'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required style="width:110px;padding:6px 8px;border:1px solid #c9c9c9;border-radius:6px;">
								<button type="submit" class="train-button" style="background:#0d8f49;border-color:#0b7a3e;">Ofertar</button>
							</form>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		<?php else: ?>
			<p class="warn" style="margin:8px 0 0;">Todavia no se consulto el mercado de subastas.</p>
		<?php endif; ?>
	</div>

	<div class="section-panel">
		<h2 class="section-title">Game Room</h2>
		<p class="section-meta" style="margin:0;">
			Juego objetivo: <strong>Bandido Azul</strong>
			| Endpoint base: <?= htmlspecialchars($gameRoomUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
		</p>
		<div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
			<form method="post" style="margin:0;display:inline-flex;align-items:center;">
				<input type="hidden" name="action" value="bandit-blue-open">
				<button type="submit" class="train-button">Abrir Bandido Azul (cURL)</button>
			</form>
			<form method="post" style="margin:0;display:inline-flex;align-items:center;">
				<input type="hidden" name="action" value="bandit-blue-run">
				<button type="submit" class="train-button" style="background:#0d8f49;border-color:#0b7a3e;">Iniciar corrida Bandido Azul</button>
			</form>
		</div>

		<?php if (!empty($banditBlueOpenResult['attempted'])): ?>
			<p class="<?= !empty($banditBlueOpenResult['saved']) ? 'ok' : 'warn' ?>" style="margin:8px 0 0;">
				Abrir juego: <?= htmlspecialchars((string) ($banditBlueOpenResult['reason'] ?? 'unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
				<?= isset($banditBlueOpenResult['gameRoomHttpStatus']) ? ' | gameRoom HTTP ' . (int) $banditBlueOpenResult['gameRoomHttpStatus'] : '' ?>
				<?= isset($banditBlueOpenResult['banditHttpStatus']) ? ' | bandit HTTP ' . (int) $banditBlueOpenResult['banditHttpStatus'] : '' ?>
				<?= !empty($banditBlueOpenResult['containsHandlePlay']) ? ' | banditHandlePlay: SI' : ' | banditHandlePlay: NO' ?>
			</p>
		<?php endif; ?>

		<?php if (!empty($banditBlueRunResult['attempted'])): ?>
			<p class="<?= !empty($banditBlueRunResult['saved']) ? 'ok' : 'warn' ?>" style="margin:8px 0 0;">
				Correr juego: <?= htmlspecialchars((string) ($banditBlueRunResult['reason'] ?? 'unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
				<?= isset($banditBlueRunResult['playHttpStatus']) ? ' | play HTTP ' . (int) $banditBlueRunResult['playHttpStatus'] : '' ?>
				<?= isset($banditBlueRunResult['rewardHttpStatus']) ? ' | reward HTTP ' . (int) $banditBlueRunResult['rewardHttpStatus'] : '' ?>
				<?= !empty($banditBlueRunResult['runId']) ? ' | runId ' . htmlspecialchars((string) $banditBlueRunResult['runId'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
				<?= !empty($banditBlueRunResult['error']) ? ' | Error cURL: ' . htmlspecialchars((string) $banditBlueRunResult['error'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
			</p>
			<?php if (!empty($banditBlueRunResult['rewardSnippet'])): ?>
				<p class="section-meta" style="margin:6px 0 0;">Respuesta reward: <?= htmlspecialchars((string) $banditBlueRunResult['rewardSnippet'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
			<?php endif; ?>
		<?php endif; ?>
	</div>

	<div class="section-panel">
		<h2 class="section-title">Misiones diarias</h2>
		<form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
			<input type="hidden" name="action" value="dailies-load">
			<button type="submit" class="train-button">Ver misiones diarias</button>
			<span class="section-meta" style="margin:0;">Endpoint: <?= htmlspecialchars($dailiesUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
		</form>

		<?php if (!empty($dailiesResult['attempted'])): ?>
			<p class="section-meta" style="margin:8px 0 0;">
				Fuente: <?= htmlspecialchars((string) ($dailiesResult['url'] ?? $dailiesUrl), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
				<?= isset($dailiesResult['httpStatus']) ? ' | HTTP ' . (int) $dailiesResult['httpStatus'] : '' ?>
				<?= isset($dailiesResult['bodyLength']) ? ' | HTML ' . number_format((int) $dailiesResult['bodyLength']) . ' bytes' : '' ?>
				<?= isset($dailiesResult['itemsCount']) ? ' | Misiones ' . (int) $dailiesResult['itemsCount'] : '' ?>
				<?= isset($dailiesResult['claimableCount']) ? ' | Reclamar ' . (int) $dailiesResult['claimableCount'] : '' ?>
			</p>

			<?php if (empty($dailiesResult['saved'])): ?>
				<p class="warn" style="margin:8px 0 0;">No se pudieron cargar las misiones diarias (<?= htmlspecialchars((string) ($dailiesResult['reason'] ?? 'unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>).</p>
			<?php elseif (empty($dailiesResult['items']) || !is_array($dailiesResult['items'])): ?>
				<p class="warn" style="margin:8px 0 0;">No se detectaron misiones diarias para mostrar.</p>
			<?php else: ?>
				<div style="margin-top:10px;display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:10px;">
					<?php foreach ((array) ($dailiesResult['items'] ?? []) as $dailyItem): ?>
						<?php
						$dailyId = trim((string) ($dailyItem['dailyId'] ?? ''));
						$dailyDescription = trim((string) ($dailyItem['description'] ?? ''));
						$dailyProgressText = trim((string) ($dailyItem['progressText'] ?? ''));
						$dailyProgressPercent = trim((string) ($dailyItem['progressPercent'] ?? ''));
						$dailyStatus = strtoupper(trim((string) ($dailyItem['status'] ?? '')));
						$dailyIsChest = !empty($dailyItem['isChest']);
						$dailyCompleted = !empty($dailyItem['isCompleted']);
						$dailyButtonText = trim((string) ($dailyItem['buttonText'] ?? ''));
						$dailyClaimable = !empty($dailyItem['isClaimable']);
						$dailyClaimUrl = trim((string) ($dailyItem['claimUrl'] ?? ''));
						$dailyRewards = is_array($dailyItem['rewards'] ?? null) ? (array) $dailyItem['rewards'] : [];
						?>
						<div style="background:#fbfcff;border:1px solid <?= $dailyClaimable ? '#c4e8cd' : '#d7e0ee' ?>;border-radius:10px;padding:10px;">
							<p style="margin:0 0 6px;font-weight:700;color:#162742;"><?= htmlspecialchars($dailyDescription !== '' ? $dailyDescription : 'Mision sin descripcion', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?= $dailyIsChest ? ' <span class="section-meta" style="font-weight:600;">(CHEST)</span>' : '' ?></p>
							<p class="section-meta" style="margin:0;">ID <?= htmlspecialchars($dailyId !== '' ? $dailyId : '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?= $dailyProgressText !== '' ? ' | Progreso ' . htmlspecialchars($dailyProgressText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?><?= $dailyProgressPercent !== '' ? ' | ' . htmlspecialchars($dailyProgressPercent, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '%' : '' ?><?= $dailyStatus !== '' ? ' | Status ' . htmlspecialchars($dailyStatus, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?><?= $dailyCompleted ? ' | Completada' : '' ?></p>
							<?php if (!empty($dailyRewards)): ?>
								<p class="section-meta" style="margin:6px 0 0;">Premios:
									<?php
									$dailyRewardTokens = [];
									foreach ($dailyRewards as $dailyReward) {
										$rewardType = trim((string) ($dailyReward['type'] ?? ''));
										$rewardAmount = trim((string) ($dailyReward['amount'] ?? ''));
										$dailyRewardTokens[] = trim($rewardType . ' ' . $rewardAmount);
									}
									echo htmlspecialchars(implode(' | ', array_filter($dailyRewardTokens)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
									?>
								</p>
							<?php endif; ?>
							<div style="margin-top:8px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
								<?php if ($dailyClaimable): ?>
									<form method="post" style="margin:0;display:inline-flex;align-items:center;">
										<input type="hidden" name="action" value="dailies-claim">
										<input type="hidden" name="daily_id" value="<?= htmlspecialchars($dailyId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
										<input type="hidden" name="daily_claim_url" value="<?= htmlspecialchars($dailyClaimUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
										<button type="submit" class="train-button" style="background:#0d8f49;border-color:#0b7a3e;">Reclamar</button>
									</form>
								<?php else: ?>
									<span class="section-meta" style="margin:0;"><?= htmlspecialchars($dailyButtonText !== '' ? $dailyButtonText : 'Sin boton de reclamo', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?= $dailyStatus === 'ACTIVE' ? ' (debes completarla)' : '' ?><?= $dailyStatus === 'FINISHED' ? ' (ya completada)' : '' ?></span>
								<?php endif; ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		<?php else: ?>
			<p class="warn" style="margin:8px 0 0;">Presiona el boton para consultar misiones diarias con la sesion cURL activa.</p>
		<?php endif; ?>

		<?php if (!empty($dailiesClaimResult['attempted'])): ?>
			<p class="<?= !empty($dailiesClaimResult['saved']) ? 'ok' : 'warn' ?>" style="margin:8px 0 0;">
				Resultado reclamar: <?= htmlspecialchars((string) ($dailiesClaimResult['reason'] ?? 'unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
				<?= isset($dailiesClaimResult['dailyId']) && (string) $dailiesClaimResult['dailyId'] !== '' ? ' | Daily ID ' . htmlspecialchars((string) $dailiesClaimResult['dailyId'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
				<?= !empty($dailiesClaimResult['claimUrl']) ? ' | GET ' . htmlspecialchars((string) ($dailiesClaimResult['claimUrl'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
				<?= !empty($dailiesClaimResult['url']) ? ' | Ejecutada ' . htmlspecialchars((string) ($dailiesClaimResult['url'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
				<?= isset($dailiesClaimResult['httpStatus']) ? ' | HTTP ' . (int) $dailiesClaimResult['httpStatus'] : '' ?>
				<?= !empty($dailiesClaimResult['error']) ? ' | Error cURL: ' . htmlspecialchars((string) $dailiesClaimResult['error'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
			</p>
			<?php if (!empty($dailiesClaimResult['responseSnippet'])): ?>
				<p class="section-meta" style="margin:6px 0 0;">Respuesta: <?= htmlspecialchars((string) $dailiesClaimResult['responseSnippet'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
			<?php endif; ?>
		<?php endif; ?>
	</div>

	<div class="section-panel">
		<h2 class="section-title">Tutorial Missions</h2>
		<?php
		$tutorialChecked = !empty($tutorialMissionState['checked']);
		$hasTutorialBallContainer = !empty($tutorialMissionState['hasTutorialBallContainer']);
		$hasMissionDropdown = !empty($tutorialMissionState['hasMissionDropdown']);
		$selectedMissionTitle = trim((string) ($tutorialMissionState['selectedMissionTitle'] ?? ''));
		$selectedMissionDescription = trim((string) ($tutorialMissionState['selectedMissionDescription'] ?? ''));
		$hasInProgressPanel = !empty($tutorialMissionState['hasInProgressPanel']);
		$inProgressTitle = trim((string) ($tutorialMissionState['inProgressTitle'] ?? ''));
		$inProgressDescription = trim((string) ($tutorialMissionState['inProgressDescription'] ?? ''));
		$inProgressSummary = trim((string) ($tutorialMissionState['inProgressSummary'] ?? ''));
		$hasRewardMissionForm = !empty($tutorialMissionState['hasRewardMissionForm']);
		$rewardActionUrl = trim((string) ($tutorialMissionState['rewardActionUrl'] ?? serverUrl('betaMissions.html')));
		$rewardMethod = strtoupper(trim((string) ($tutorialMissionState['rewardMethod'] ?? 'POST')));
		if (!in_array($rewardMethod, ['POST', 'GET'], true)) {
			$rewardMethod = 'POST';
		}
		$hasSkipOption = !empty($tutorialMissionState['hasSkipOption']);
		$skipActionUrl = trim((string) ($tutorialMissionState['skipActionUrl'] ?? serverUrl('betaMissions.html')));
		$skipMethod = strtoupper(trim((string) ($tutorialMissionState['skipMethod'] ?? 'POST')));
		if (!in_array($skipMethod, ['POST', 'GET'], true)) {
			$skipMethod = 'POST';
		}
		$availableMissionCount = (int) ($tutorialMissionState['availableMissionCount'] ?? 0);
		$tutorialReason = trim((string) ($tutorialMissionState['reason'] ?? ''));
		$tutorialCompleteAttempted = !empty($tutorialMissionCompleteResult['attempted']);
		$tutorialCompleteSaved = !empty($tutorialMissionCompleteResult['saved']);
		$tutorialCompleteReason = trim((string) ($tutorialMissionCompleteResult['reason'] ?? ''));
		$tutorialCompleteMethod = strtoupper(trim((string) ($tutorialMissionCompleteResult['method'] ?? 'POST')));
		$tutorialCompleteUrl = trim((string) ($tutorialMissionCompleteResult['url'] ?? ''));
		$tutorialCompleteFirstHttp = (int) ($tutorialMissionCompleteResult['firstHttpStatus'] ?? 0);
		$tutorialCompleteSecondHttp = (int) ($tutorialMissionCompleteResult['secondHttpStatus'] ?? 0);
		$tutorialCompleteError = trim((string) ($tutorialMissionCompleteResult['error'] ?? ''));
		$tutorialCompleteFirstSnippet = trim((string) ($tutorialMissionCompleteResult['firstSnippet'] ?? ''));
		$tutorialCompleteSecondSnippet = trim((string) ($tutorialMissionCompleteResult['secondSnippet'] ?? ''));
		$tutorialSkipAttempted = !empty($tutorialMissionSkipResult['attempted']);
		$tutorialSkipSaved = !empty($tutorialMissionSkipResult['saved']);
		$tutorialSkipReason = trim((string) ($tutorialMissionSkipResult['reason'] ?? ''));
		$tutorialSkipMethod = strtoupper(trim((string) ($tutorialMissionSkipResult['method'] ?? 'POST')));
		$tutorialSkipUrl = trim((string) ($tutorialMissionSkipResult['url'] ?? ''));
		$tutorialSkipHttpStatus = (int) ($tutorialMissionSkipResult['httpStatus'] ?? 0);
		$tutorialSkipError = trim((string) ($tutorialMissionSkipResult['error'] ?? ''));
		$tutorialSkipSnippet = trim((string) ($tutorialMissionSkipResult['responseSnippet'] ?? ''));
		?>
		<p class="section-meta" style="margin:0;">
			Estado: <?= $tutorialChecked ? '<strong style="color:#0b7a35;">analizado</strong>' : '<span style="color:#925f00;">sin analisis</span>' ?>
			| .tutorialBallContainer: <?= $hasTutorialBallContainer ? '<strong style="color:#0b7a35;">SI</strong>' : '<span style="color:#925f00;">NO</span>' ?>
			| #missionDropdown: <?= $hasMissionDropdown ? '<strong style="color:#0b7a35;">SI</strong>' : '<span style="color:#925f00;">NO</span>' ?>
			| #inProgressPanel: <?= $hasInProgressPanel ? '<strong style="color:#0b7a35;">SI</strong>' : '<span style="color:#925f00;">NO</span>' ?>
			| #rewardMission: <?= $hasRewardMissionForm ? '<strong style="color:#0b7a35;">SI</strong>' : '<span style="color:#925f00;">NO</span>' ?>
			| Skip disponible: <?= $hasSkipOption ? '<strong style="color:#0b7a35;">SI</strong>' : '<span style="color:#925f00;">NO</span>' ?>
			| Metodo reward: <?= htmlspecialchars($rewardMethod, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
			| Metodo skip: <?= htmlspecialchars($skipMethod, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
			| Misiones detectadas: <?= number_format($availableMissionCount) ?>
			<?php if ($tutorialReason !== ''): ?> | Estado parser: <?= htmlspecialchars($tutorialReason, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?php endif; ?>
		</p>

		<?php if ($inProgressSummary !== ''): ?>
			<p class="section-meta" style="margin:8px 0 0;"><strong>Mision en progreso:</strong> <?= htmlspecialchars($inProgressSummary, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
		<?php endif; ?>
		<?php if ($inProgressTitle !== ''): ?>
			<p class="section-meta" style="margin:6px 0 0;"><strong>Titulo mision:</strong> <?= htmlspecialchars($inProgressTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
		<?php endif; ?>
		<?php if ($inProgressDescription !== ''): ?>
			<p class="section-meta" style="margin:6px 0 0;"><strong>Descripcion mision:</strong> <?= htmlspecialchars($inProgressDescription, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
		<?php endif; ?>
		<?php if ($selectedMissionTitle !== '' && $selectedMissionTitle !== $inProgressTitle): ?>
			<p class="section-meta" style="margin:6px 0 0;"><strong>Mision seleccionada:</strong> <?= htmlspecialchars($selectedMissionTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
		<?php endif; ?>
		<?php if ($selectedMissionDescription !== '' && $selectedMissionDescription !== $inProgressDescription): ?>
			<p class="section-meta" style="margin:6px 0 0;"><strong>Descripcion seleccionada:</strong> <?= htmlspecialchars($selectedMissionDescription, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
		<?php endif; ?>

		<div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
			<?php if ($hasRewardMissionForm): ?>
				<form method="post" style="margin:0;display:inline-flex;align-items:center;">
					<input type="hidden" name="action" value="tutorial-mission-complete">
					<input type="hidden" name="tutorial_complete_url" value="<?= htmlspecialchars($rewardActionUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
					<button type="submit" class="train-button" style="background:#0d8f49;border-color:#0b7a3e;">Recolectar (COMPLETE) + Iniciar (START) por cURL</button>
				</form>
			<?php else: ?>
				<p class="warn" style="margin:0;">No se detecta formulario #rewardMission para recolectar en este momento.</p>
			<?php endif; ?>
			<?php if ($hasSkipOption): ?>
				<form method="post" style="margin:0;display:inline-flex;align-items:center;">
					<input type="hidden" name="action" value="tutorial-mission-skip">
					<input type="hidden" name="tutorial_skip_url" value="<?= htmlspecialchars($skipActionUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
					<button type="submit" class="train-button" style="background:#925f00;border-color:#7c4f00;">Skip mission (cURL)</button>
				</form>
			<?php endif; ?>
		</div>

		<?php if ($tutorialCompleteAttempted): ?>
			<p class="<?= $tutorialCompleteSaved ? 'ok' : 'warn' ?>" style="margin:8px 0 0;">
				Resultado tutorial: <?= htmlspecialchars($tutorialCompleteReason !== '' ? $tutorialCompleteReason : 'unknown', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
				<?php if ($tutorialCompleteMethod !== ''): ?> | Metodo <?= htmlspecialchars($tutorialCompleteMethod, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?php endif; ?>
				<?php if ($tutorialCompleteUrl !== ''): ?> | Endpoint <?= htmlspecialchars($tutorialCompleteUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?php endif; ?>
				<?php if ($tutorialCompleteFirstHttp > 0): ?> | 1a llamada HTTP <?= $tutorialCompleteFirstHttp ?><?php endif; ?>
				<?php if ($tutorialCompleteSecondHttp > 0): ?> | 2a llamada HTTP <?= $tutorialCompleteSecondHttp ?><?php endif; ?>
				<?php if ($tutorialCompleteError !== ''): ?> | Error cURL: <?= htmlspecialchars($tutorialCompleteError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?php endif; ?>
			</p>
			<?php if ($tutorialCompleteFirstSnippet !== '' || $tutorialCompleteSecondSnippet !== ''): ?>
				<p class="section-meta" style="margin:6px 0 0;">
					Respuesta 1: <?= htmlspecialchars($tutorialCompleteFirstSnippet !== '' ? $tutorialCompleteFirstSnippet : '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
					| Respuesta 2: <?= htmlspecialchars($tutorialCompleteSecondSnippet !== '' ? $tutorialCompleteSecondSnippet : '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
				</p>
			<?php endif; ?>
		<?php endif; ?>

		<?php if ($tutorialSkipAttempted): ?>
			<p class="<?= $tutorialSkipSaved ? 'ok' : 'warn' ?>" style="margin:8px 0 0;">
				Resultado skip: <?= htmlspecialchars($tutorialSkipReason !== '' ? $tutorialSkipReason : 'unknown', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
				<?php if ($tutorialSkipMethod !== ''): ?> | Metodo <?= htmlspecialchars($tutorialSkipMethod, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?php endif; ?>
				<?php if ($tutorialSkipUrl !== ''): ?> | Endpoint <?= htmlspecialchars($tutorialSkipUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?php endif; ?>
				<?php if ($tutorialSkipHttpStatus > 0): ?> | HTTP <?= $tutorialSkipHttpStatus ?><?php endif; ?>
				<?php if ($tutorialSkipError !== ''): ?> | Error cURL: <?= htmlspecialchars($tutorialSkipError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?php endif; ?>
			</p>
			<?php if ($tutorialSkipSnippet !== ''): ?>
				<p class="section-meta" style="margin:6px 0 0;">Respuesta skip: <?= htmlspecialchars($tutorialSkipSnippet, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
			<?php endif; ?>
		<?php endif; ?>
	</div>

	<div class="section-panel">
		<h2 class="section-title">Promociones</h2>
		<?php
		$freeStarterFound = !empty($freeStarterPackResult['found']);
		$freeStarterClaimButtonFound = !empty($freeStarterPackResult['claimButtonFound']);
		$freeStarterSource = trim((string) ($freeStarterPackResult['source'] ?? ''));
		$freeStarterReason = trim((string) ($freeStarterPackResult['reason'] ?? ''));
		$freeStarterClaimUrl = trim((string) ($freeStarterPackResult['claimUrl'] ?? ''));
		$freeStarterClaimAttempted = !empty($freeStarterPackClaimResult['attempted']);
		$freeStarterClaimSaved = !empty($freeStarterPackClaimResult['saved']);
		$freeStarterClaimReason = trim((string) ($freeStarterPackClaimResult['reason'] ?? ''));
		$freeStarterClaimHttpStatus = (int) ($freeStarterPackClaimResult['httpStatus'] ?? 0);
		$freeStarterClaimSnippet = trim((string) ($freeStarterPackClaimResult['responseSnippet'] ?? ''));
		$freeStarterClaimError = trim((string) ($freeStarterPackClaimResult['error'] ?? ''));
		$freeStarterOpenAttempted = !empty($freeStarterPackOpenResult['attempted']);
		$freeStarterOpenSaved = !empty($freeStarterPackOpenResult['saved']);
		$freeStarterOpenReason = trim((string) ($freeStarterPackOpenResult['reason'] ?? ''));
		$freeStarterOpenHttpStatus = (int) ($freeStarterPackOpenResult['httpStatus'] ?? 0);
		$freeStarterOpenUrl = trim((string) ($freeStarterPackOpenResult['url'] ?? ''));
		$freeStarterOpenBodyLength = (int) ($freeStarterPackOpenResult['bodyLength'] ?? 0);
		$freeStarterOpenError = trim((string) ($freeStarterPackOpenResult['error'] ?? ''));
		?>
		<p class="section-meta" style="margin:0;">
			Deteccion DOM: <?= $freeStarterFound ? '<strong style="color:#0b7a35;">FREE_STARTER_PACK detectado</strong>' : '<span style="color:#925f00;">No detectado en esta carga</span>' ?>
			| Boton CLAIM: <?= $freeStarterClaimButtonFound ? '<strong style="color:#0b7a35;">detectado</strong>' : '<span style="color:#925f00;">no detectado</span>' ?>
			<?php if ($freeStarterSource !== ''): ?>
				| Fuente: <?= htmlspecialchars($freeStarterSource, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
			<?php endif; ?>
			<?php if ($freeStarterReason !== ''): ?>
				| Estado: <?= htmlspecialchars($freeStarterReason, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
			<?php endif; ?>
		</p>
		<div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
			<form method="post" style="margin:0;display:inline-flex;align-items:center;">
				<input type="hidden" name="action" value="free-starter-pack-open">
				<input type="hidden" name="free_starter_pack_open_url" value="<?= htmlspecialchars((string) ($freeStarterPackResult['openUrl'] ?? serverUrl('shop.html?shopType=PROMOTIONS')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
				<button type="submit" class="train-button">Abrir promociones (cURL)</button>
			</form>
			<?php if ($freeStarterFound && $freeStarterPackProxyClaimUrl !== ''): ?>
				<a class="battle-link" href="<?= htmlspecialchars($freeStarterPackProxyClaimUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">Abrir ruta CLAIM detectada</a>
			<?php endif; ?>
			<?php if ($freeStarterFound || $freeStarterClaimButtonFound): ?>
				<form method="post" style="margin:0;display:inline-flex;align-items:center;">
					<input type="hidden" name="action" value="free-starter-pack-claim">
					<input type="hidden" name="free_starter_pack_claim_url" value="<?= htmlspecialchars($freeStarterClaimUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
					<button type="submit" class="train-button" style="background:#0d8f49;border-color:#0b7a3e;">Claim FREE_STARTER_PACK</button>
				</form>
			<?php endif; ?>
		</div>
		<?php if ($freeStarterOpenAttempted): ?>
			<p class="<?= $freeStarterOpenSaved ? 'ok' : 'warn' ?>" style="margin:8px 0 0;">
				Resultado promociones: <?= htmlspecialchars($freeStarterOpenReason !== '' ? $freeStarterOpenReason : 'unknown', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
				<?php if ($freeStarterOpenHttpStatus > 0): ?> | HTTP <?= $freeStarterOpenHttpStatus ?><?php endif; ?>
				<?php if ($freeStarterOpenBodyLength > 0): ?> | HTML <?= number_format($freeStarterOpenBodyLength) ?> bytes<?php endif; ?>
				<?php if ($freeStarterOpenUrl !== ''): ?> | URL: <?= htmlspecialchars($freeStarterOpenUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?php endif; ?>
				<?php if ($freeStarterOpenError !== ''): ?> | Error cURL: <?= htmlspecialchars($freeStarterOpenError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?php endif; ?>
			</p>
		<?php endif; ?>
		<?php if ($freeStarterClaimAttempted): ?>
			<p class="<?= $freeStarterClaimSaved ? 'ok' : 'warn' ?>" style="margin:8px 0 0;">
				Resultado claim: <?= htmlspecialchars($freeStarterClaimReason !== '' ? $freeStarterClaimReason : 'unknown', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
				<?php if ($freeStarterClaimHttpStatus > 0): ?> | HTTP <?= $freeStarterClaimHttpStatus ?><?php endif; ?>
				<?php if ($freeStarterClaimError !== ''): ?> | Error cURL: <?= htmlspecialchars($freeStarterClaimError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?php endif; ?>
			</p>
			<?php if ($freeStarterClaimSnippet !== ''): ?>
				<p class="section-meta" style="margin:6px 0 0;">Respuesta: <?= htmlspecialchars($freeStarterClaimSnippet, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
			<?php endif; ?>
		<?php else: ?>
			<p class="warn" style="margin:8px 0 0;">El boton Claim intenta reclamar directamente desde el panel usando la sesion cURL actual.</p>
		<?php endif; ?>
	</div>

	<div class="section-panel">
		<h2 class="section-title">Notificaciones</h2>
		<form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
			<input type="hidden" name="action" value="notifications-load">
			<button type="submit" class="train-button">Ver notificaciones</button>
			<span class="section-meta" style="margin:0;">
				Endpoint: <?= htmlspecialchars($notificationsUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
			</span>
		</form>

		<?php if (!empty($notificationsResult['attempted'])): ?>
			<p class="section-meta" style="margin:8px 0 0;">
				Fuente: <?= htmlspecialchars((string) ($notificationsResult['url'] ?? $notificationsUrl), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
				<?= isset($notificationsResult['httpStatus']) ? ' | HTTP ' . (int) $notificationsResult['httpStatus'] : '' ?>
				<?= isset($notificationsResult['bodyLength']) ? ' | HTML ' . number_format((int) $notificationsResult['bodyLength']) . ' bytes' : '' ?>
				<?= isset($notificationsResult['itemsCount']) ? ' | Items ' . (int) $notificationsResult['itemsCount'] : '' ?>
			</p>
			<?php if (empty($notificationsResult['saved'])): ?>
				<p class="warn" style="margin:8px 0 0;">No se pudieron cargar notificaciones (<?= htmlspecialchars((string) ($notificationsResult['reason'] ?? 'unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>).</p>
			<?php elseif (empty($notificationsResult['items']) || !is_array($notificationsResult['items'])): ?>
				<p class="warn" style="margin:8px 0 0;">No se detectaron items de notificacion para mostrar.</p>
			<?php else: ?>
				<div style="margin-top:10px;padding:10px;border:1px solid #d7e0ee;border-radius:8px;background:#f8fbff;">
					<p style="margin:0 0 6px;"><strong>Listado parseado:</strong></p>
					<ul style="margin:0;padding-left:18px;display:flex;flex-direction:column;gap:6px;">
						<?php foreach ((array) ($notificationsResult['items'] ?? []) as $notifItem): ?>
							<?php
							$itemText = trim((string) ($notifItem['text'] ?? ''));
							$itemWhen = trim((string) ($notifItem['when'] ?? ''));
							$itemWhenAbsolute = trim((string) ($notifItem['whenAbsolute'] ?? ''));
							$itemUrl = trim((string) ($notifItem['url'] ?? ''));
							$itemUnread = !empty($notifItem['unread']);
							if ($itemText === '') {
								continue;
							}
							?>
							<li>
								<?php if ($itemUnread): ?><strong style="color:#0b4f9f;">[Nuevo]</strong> <?php endif; ?>
								<?= htmlspecialchars($itemText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
								<?php if ($itemWhen !== ''): ?>
									<span class="section-meta" style="margin-left:6px;">(<?= htmlspecialchars($itemWhen, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>)</span>
								<?php endif; ?>
								<?php if ($itemWhenAbsolute !== ''): ?>
									<span class="section-meta" style="margin-left:6px;">[<?= htmlspecialchars($itemWhenAbsolute, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>]</span>
								<?php endif; ?>
								<?php if ($itemUrl !== ''): ?>
									<a class="battle-link" href="<?= htmlspecialchars($itemUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" style="margin-left:8px;">Abrir</a>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>
		<?php else: ?>
			<p class="warn" style="margin:8px 0 0;">Presiona el boton para consultar notificaciones con la sesion cURL activa.</p>
		<?php endif; ?>
	</div>

	<div class="section-panel">
		<h2 class="section-title">Mercado de productos</h2>
		<?php
		$selectedMarketType = strtoupper(trim((string) ($_POST['product_market_type'] ?? (($productMarketOffersResult['type'] ?? '') !== '' ? (string) $productMarketOffersResult['type'] : 'FOOD'))));
		if (!in_array($selectedMarketType, ['FOOD', 'GIFT', 'WEAPON', 'TICKET'], true)) {
			$selectedMarketType = 'FOOD';
		}
		$selectedMarketQuality = trim((string) ($_POST['product_market_quality'] ?? (($productMarketOffersResult['quality'] ?? '') !== '' ? (string) $productMarketOffersResult['quality'] : '2')));
		if (preg_match('/^\d+$/', $selectedMarketQuality) !== 1) {
			$selectedMarketQuality = ($selectedMarketType === 'WEAPON' || $selectedMarketType === 'TICKET') ? '1' : '2';
		}
		$selectedMarketCountryId = trim((string) ($_POST['product_market_country_id'] ?? (($productMarketOffersResult['countryId'] ?? '') !== '' ? (string) $productMarketOffersResult['countryId'] : '-1')));
		if (preg_match('/^-?\d+$/', $selectedMarketCountryId) !== 1) {
			$selectedMarketCountryId = '-1';
		}
		$selectedMarketPage = trim((string) ($_POST['product_market_page'] ?? (($productMarketOffersResult['page'] ?? '') !== '' ? (string) $productMarketOffersResult['page'] : '')));
		$marketCountryOptions = [
			['id' => '-1', 'name' => 'Todos'],
		];
		if (is_array($travelCountryListResult['countries'] ?? null)) {
			foreach ((array) ($travelCountryListResult['countries'] ?? []) as $countryItem) {
				$countryItemId = trim((string) ($countryItem['id'] ?? ''));
				$countryItemName = trim((string) ($countryItem['name'] ?? ''));
				if ($countryItemId === '' || $countryItemName === '') {
					continue;
				}
				$marketCountryOptions[] = [
					'id' => $countryItemId,
					'name' => $countryItemName,
				];
			}
		}
		?>

		<form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
			<input type="hidden" name="action" value="product-market-load">
			<button type="submit" class="train-button">Consultar mercado</button>
			<span class="section-meta" style="margin:0;">Endpoint: <?= htmlspecialchars($productMarketUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
		</form>

		<form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:10px;">
			<input type="hidden" name="action" value="product-market-offers-load">
			<label for="product_market_type"><strong>Tipo:</strong></label>
			<select id="product_market_type" name="product_market_type" class="battle-action-button" style="padding:3px 6px;height:32px;min-width:150px;">
				<option value="FOOD" <?= $selectedMarketType === 'FOOD' ? 'selected' : '' ?>>FOOD</option>
				<option value="GIFT" <?= $selectedMarketType === 'GIFT' ? 'selected' : '' ?>>GIFT</option>
				<option value="WEAPON" <?= $selectedMarketType === 'WEAPON' ? 'selected' : '' ?>>WEAPON</option>
				<option value="TICKET" <?= $selectedMarketType === 'TICKET' ? 'selected' : '' ?>>TICKET</option>
			</select>
			<label for="product_market_quality"><strong>Q:</strong></label>
			<input id="product_market_quality" type="number" min="1" name="product_market_quality" value="<?= htmlspecialchars($selectedMarketQuality, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="battle-action-button" style="width:80px;height:32px;padding:3px 6px;">
			<label for="product_market_country_id"><strong>countryId:</strong></label>
			<select id="product_market_country_id" name="product_market_country_id" class="battle-action-button" style="padding:3px 6px;height:32px;min-width:180px;">
				<?php foreach ($marketCountryOptions as $countryOption): ?>
					<?php $countryOptionId = trim((string) ($countryOption['id'] ?? '')); ?>
					<?php $countryOptionName = trim((string) ($countryOption['name'] ?? '')); ?>
					<?php if ($countryOptionId === '' || $countryOptionName === '') { continue; } ?>
					<option value="<?= htmlspecialchars($countryOptionId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $selectedMarketCountryId === $countryOptionId ? 'selected' : '' ?>>
						<?= htmlspecialchars($countryOptionName . ' (' . $countryOptionId . ')', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
					</option>
				<?php endforeach; ?>
			</select>
			<label for="product_market_page"><strong>page:</strong></label>
			<input id="product_market_page" type="text" name="product_market_page" value="<?= htmlspecialchars($selectedMarketPage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="1" class="battle-action-button" style="width:70px;height:32px;padding:3px 6px;">
			<button type="submit" class="train-button">Consultar ofertas</button>
		</form>

		<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;">
			<form method="post" style="margin:0;">
				<input type="hidden" name="action" value="product-market-offers-load">
				<input type="hidden" name="product_market_type" value="FOOD">
				<input type="hidden" name="product_market_quality" value="2">
				<input type="hidden" name="product_market_country_id" value="-1">
				<input type="hidden" name="product_market_page" value="">
				<button type="submit" class="train-button">FOOD Q2</button>
			</form>
			<form method="post" style="margin:0;">
				<input type="hidden" name="action" value="product-market-offers-load">
				<input type="hidden" name="product_market_type" value="GIFT">
				<input type="hidden" name="product_market_quality" value="2">
				<input type="hidden" name="product_market_country_id" value="-1">
				<input type="hidden" name="product_market_page" value="">
				<button type="submit" class="train-button">GIFT Q2</button>
			</form>
			<form method="post" style="margin:0;">
				<input type="hidden" name="action" value="product-market-offers-load">
				<input type="hidden" name="product_market_type" value="WEAPON">
				<input type="hidden" name="product_market_quality" value="1">
				<input type="hidden" name="product_market_country_id" value="-1">
				<input type="hidden" name="product_market_page" value="">
				<button type="submit" class="train-button">WEAPON Q1</button>
			</form>
			<form method="post" style="margin:0;">
				<input type="hidden" name="action" value="product-market-offers-load">
				<input type="hidden" name="product_market_type" value="TICKET">
				<input type="hidden" name="product_market_quality" value="1">
				<input type="hidden" name="product_market_country_id" value="-1">
				<input type="hidden" name="product_market_page" value="">
				<button type="submit" class="train-button">TICKET Q1</button>
			</form>
		</div>

		<?php if (!empty($productMarketResult['attempted'])): ?>
			<p class="section-meta" style="margin:8px 0 0;">
				Mercado fuente: <?= htmlspecialchars((string) ($productMarketResult['url'] ?? $productMarketUrl), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
				<?= isset($productMarketResult['httpStatus']) ? ' | HTTP ' . (int) $productMarketResult['httpStatus'] : '' ?>
				<?= isset($productMarketResult['bodyLength']) ? ' | HTML ' . number_format((int) $productMarketResult['bodyLength']) . ' bytes' : '' ?>
			</p>
		<?php endif; ?>

		<?php if (!empty($productMarketOffersResult['attempted'])): ?>
			<?php if (!empty($productMarketBuyResult['attempted'])): ?>
				<p class="<?= !empty($productMarketBuyResult['saved']) ? 'ok' : 'warn' ?>" style="margin:8px 0 0;">
					<strong>Compra oferta:</strong> <?= htmlspecialchars((string) ($productMarketBuyResult['reason'] ?? 'unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
					<?= isset($productMarketBuyResult['httpStatus']) ? ' | HTTP ' . (int) $productMarketBuyResult['httpStatus'] : '' ?>
					<?= !empty($productMarketBuyResult['offerId']) ? ' | Offer #' . htmlspecialchars((string) $productMarketBuyResult['offerId'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
					<?= !empty($productMarketBuyResult['quantity']) ? ' | Qty ' . htmlspecialchars((string) $productMarketBuyResult['quantity'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
				</p>
				<?php if (!empty($productMarketBuyResult['responseSnippet'])): ?>
					<p class="section-meta" style="margin:4px 0 0;">Respuesta: <?= htmlspecialchars((string) $productMarketBuyResult['responseSnippet'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
				<?php endif; ?>
			<?php endif; ?>

			<p class="section-meta" style="margin:8px 0 0;">
				Ofertas fuente: <?= htmlspecialchars((string) ($productMarketOffersResult['url'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
				<?= isset($productMarketOffersResult['httpStatus']) ? ' | HTTP ' . (int) $productMarketOffersResult['httpStatus'] : '' ?>
				<?= isset($productMarketOffersResult['itemsCount']) ? ' | Items ' . (int) $productMarketOffersResult['itemsCount'] : '' ?>
			</p>
			<?php if (empty($productMarketOffersResult['saved'])): ?>
				<p class="warn" style="margin:8px 0 0;">No se pudieron cargar ofertas de mercado (<?= htmlspecialchars((string) ($productMarketOffersResult['reason'] ?? 'unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>).</p>
			<?php elseif (empty($productMarketOffersResult['offers']) || !is_array($productMarketOffersResult['offers'])): ?>
				<p class="warn" style="margin:8px 0 0;">No se detectaron ofertas para ese filtro.</p>
			<?php else: ?>
				<div style="margin-top:10px;padding:10px;border:1px solid #d7e0ee;border-radius:8px;background:#f8fbff;">
					<p style="margin:0 0 6px;"><strong>Listado parseado de ofertas:</strong></p>
					<ul style="margin:0;padding-left:18px;display:flex;flex-direction:column;gap:10px;">
						<?php foreach ((array) ($productMarketOffersResult['offers'] ?? []) as $offerItem): ?>
							<?php
							$offerTitle = trim((string) ($offerItem['title'] ?? ''));
							$offerPrice = trim((string) ($offerItem['price'] ?? ''));
							$offerPriceGold = trim((string) ($offerItem['priceGold'] ?? ''));
							$offerSeller = trim((string) ($offerItem['seller'] ?? ''));
							$offerCompany = trim((string) ($offerItem['company'] ?? ''));
							$offerCountry = trim((string) ($offerItem['country'] ?? ''));
							$offerQuantity = trim((string) ($offerItem['quantity'] ?? ''));
							$offerMaxQuantity = trim((string) ($offerItem['maxQuantity'] ?? ''));
							$offerId = trim((string) ($offerItem['offerId'] ?? ''));
							$offerCurrencyId = trim((string) ($offerItem['currencyId'] ?? ''));
							$offerCanBuy = !empty($offerItem['canBuy']);
							$offerBuyStatus = trim((string) ($offerItem['buyStatus'] ?? ''));
							$offerUrl = trim((string) ($offerItem['url'] ?? ''));
							if ($offerTitle === '') {
								continue;
							}
							$defaultBuyQuantity = '1';
							if (preg_match('/^\d+$/', $offerQuantity) === 1) {
								$defaultBuyQuantity = (string) min(1, (int) $offerQuantity);
							}
							?>
							<li>
								<strong><?= htmlspecialchars($offerTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
								<?php if ($offerId !== ''): ?><span class="section-meta" style="margin-left:6px;">| Offer #<?= htmlspecialchars($offerId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
								<?php if ($offerQuantity !== ''): ?><span class="section-meta" style="margin-left:6px;">| Qty: <?= htmlspecialchars($offerQuantity, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
								<?php if ($offerPrice !== ''): ?><span class="section-meta" style="margin-left:6px;">| Precio: <?= htmlspecialchars($offerPrice, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
								<?php if ($offerPriceGold !== ''): ?><span class="section-meta" style="margin-left:6px;">| Gold: <?= htmlspecialchars($offerPriceGold, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
								<?php if ($offerSeller !== ''): ?><span class="section-meta" style="margin-left:6px;">| Seller: <?= htmlspecialchars($offerSeller, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
								<?php if ($offerCountry !== ''): ?><span class="section-meta" style="margin-left:6px;">| Pais: <?= htmlspecialchars($offerCountry, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
								<?php if ($offerCompany !== ''): ?><span class="section-meta" style="margin-left:6px;">| Company: <?= htmlspecialchars($offerCompany, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
								<?php if ($offerUrl !== ''): ?><a class="battle-link" href="<?= htmlspecialchars($offerUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" style="margin-left:8px;">Abrir</a><?php endif; ?>

								<?php if ($offerCanBuy && $offerId !== '' && $offerCurrencyId !== ''): ?>
									<form method="post" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-top:6px;">
										<input type="hidden" name="action" value="product-market-offer-buy">
										<input type="hidden" name="product_market_buy_offer_id" value="<?= htmlspecialchars($offerId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
										<input type="hidden" name="product_market_buy_currency_id" value="<?= htmlspecialchars($offerCurrencyId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
										<input type="hidden" name="product_market_buy_source_url" value="<?= htmlspecialchars((string) ($productMarketOffersResult['url'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
										<input type="hidden" name="product_market_type" value="<?= htmlspecialchars($selectedMarketType, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
										<input type="hidden" name="product_market_quality" value="<?= htmlspecialchars($selectedMarketQuality, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
										<input type="hidden" name="product_market_country_id" value="<?= htmlspecialchars($selectedMarketCountryId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
										<input type="hidden" name="product_market_page" value="<?= htmlspecialchars($selectedMarketPage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
										<label style="font-size:12px;"><strong>Comprar:</strong></label>
										<input type="number" min="1" <?= preg_match('/^\d+$/', $offerMaxQuantity) === 1 ? 'max="' . htmlspecialchars($offerMaxQuantity, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"' : '' ?> name="product_market_buy_quantity" value="<?= htmlspecialchars($defaultBuyQuantity, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="battle-action-button" style="width:78px;height:30px;padding:3px 6px;">
										<button type="submit" class="train-button" style="height:30px;padding:4px 10px;">Comprar</button>
									</form>
								<?php elseif ($offerBuyStatus !== ''): ?>
									<span class="warn" style="display:inline-block;margin-top:6px;"><?= htmlspecialchars($offerBuyStatus, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>
		<?php else: ?>
			<p class="warn" style="margin:8px 0 0;">Usa los botones de filtro para consultar ofertas por producto/calidad.</p>
		<?php endif; ?>
	</div>

	<div class="section-panel">
		<h2 class="section-title">Viaje directo por region</h2>
		<?php
		$availableCountries = is_array($travelCountryListResult['countries'] ?? null)
			? (array) $travelCountryListResult['countries']
			: [];
		$selectedCountryId = trim((string) ($_POST['travel_country_select'] ?? ''));
		if ($selectedCountryId === '' && preg_match('/^\d+$/', (string) ($regionTravelLookupResult['travelForm']['countryId'] ?? '')) === 1) {
			$selectedCountryId = trim((string) $regionTravelLookupResult['travelForm']['countryId']);
		}
		$selectedCountryName = '';
		foreach ($availableCountries as $countryItem) {
			$countryItemId = trim((string) ($countryItem['id'] ?? ''));
			if ($countryItemId !== '' && $countryItemId === $selectedCountryId) {
				$selectedCountryName = trim((string) ($countryItem['name'] ?? ''));
				break;
			}
		}
		$availableRegionsByCountry = is_array($travelCountryRegionsResult['regions'] ?? null)
			? (array) $travelCountryRegionsResult['regions']
			: [];
		$selectedRegionFromCountry = trim((string) ($_POST['travel_region_select'] ?? ''));
		if ($selectedRegionFromCountry === '' && preg_match('/^\d+$/', (string) ($_POST['region_url'] ?? '')) === 1) {
			$selectedRegionFromCountry = trim((string) ($_POST['region_url'] ?? ''));
		}
		$regionUrlInputValue = trim((string) ($_POST['region_url'] ?? ($_POST['region_input'] ?? '')));
		if ($regionUrlInputValue === '') {
			$regionUrlInputValue = '765';
		}
		$loadedTravelForm = is_array($regionTravelLookupResult['travelForm'] ?? null)
			? (array) $regionTravelLookupResult['travelForm']
			: emptyTravelFormData();
		$loadedTicketOptions = is_array($loadedTravelForm['ticketOptions'] ?? null)
			? (array) $loadedTravelForm['ticketOptions']
			: [];
		$loadedRegionId = trim((string) ($loadedTravelForm['regionId'] ?? ''));
		if ($loadedRegionId === '') {
			$loadedRegionId = trim((string) ($regionTravelLookupResult['regionId'] ?? ''));
		}
		$loadedRegionName = trim((string) ($regionTravelLookupResult['regionName'] ?? ''));
		$loadedRegionLabel = $loadedRegionName !== ''
			? $loadedRegionName
			: ($loadedRegionId !== '' ? ('Region #' . $loadedRegionId) : 'Region objetivo');
		?>

		<p class="section-meta" style="margin:0 0 8px;">
			Paises fuente: <?= htmlspecialchars((string) ($travelCountryListResult['sourceUrl'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
			<?= isset($travelCountryListResult['httpStatus']) ? ' | HTTP ' . (int) $travelCountryListResult['httpStatus'] : '' ?>
		</p>
		<?php if (!empty($travelCountryListResult['attempted']) && empty($travelCountryListResult['saved'])): ?>
			<p class="warn" style="margin:0 0 8px;">No se pudo cargar listado de paises (<?= htmlspecialchars((string) ($travelCountryListResult['reason'] ?? 'unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>).</p>
		<?php endif; ?>

		<form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:8px;">
			<input type="hidden" name="action" value="travel-country-load">
			<label for="travel_country_select"><strong>Pais:</strong></label>
			<select id="travel_country_select" name="travel_country_select" required class="battle-action-button" style="padding:3px 6px;height:32px;min-width:220px;">
				<option value="">Selecciona un pais...</option>
				<?php foreach ($availableCountries as $countryItem): ?>
					<?php $countryItemId = trim((string) ($countryItem['id'] ?? '')); ?>
					<?php $countryItemName = trim((string) ($countryItem['name'] ?? '')); ?>
					<?php if ($countryItemId === '' || $countryItemName === '') { continue; } ?>
					<option value="<?= htmlspecialchars($countryItemId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $countryItemId === $selectedCountryId ? 'selected' : '' ?>><?= htmlspecialchars($countryItemName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
				<?php endforeach; ?>
			</select>
			<button type="submit" class="train-button">Cargar regiones</button>
		</form>

		<form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:10px;">
			<input type="hidden" name="action" value="regions-catalog-analyze-country">
			<input type="hidden" name="travel_country_select" value="<?= htmlspecialchars($selectedCountryId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
		</form>
		<?php if (!empty($regionsCatalogManualResult['attempted'])): ?>
			<p class="<?= !empty($regionsCatalogManualResult['saved']) ? 'ok' : 'warn' ?>" style="margin:0 0 8px;">
				<?= !empty($regionsCatalogManualResult['saved']) ? 'Pais analizado y guardado.' : 'No se pudo guardar el pais analizado.' ?>
				Pais: <?= htmlspecialchars((string) (($regionsCatalogManualResult['countryName'] ?? '') !== '' ? (string) $regionsCatalogManualResult['countryName'] : ((string) ($regionsCatalogManualResult['countryId'] ?? '') !== '' ? '#' . (string) $regionsCatalogManualResult['countryId'] : '-')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
				| Regiones procesadas: <?= (int) ($regionsCatalogManualResult['regionsProcessed'] ?? 0) ?>
				<?= (string) ($regionsCatalogManualResult['error'] ?? '') !== '' ? ' | Error: ' . htmlspecialchars((string) ($regionsCatalogManualResult['error'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
			</p>
		<?php endif; ?>

		<?php if (!empty($travelCountryRegionsResult['attempted'])): ?>
			<p class="section-meta" style="margin:0 0 8px;">
				Regiones fuente: <?= htmlspecialchars((string) ($travelCountryRegionsResult['sourceUrl'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
				<?= isset($travelCountryRegionsResult['httpStatus']) ? ' | HTTP ' . (int) $travelCountryRegionsResult['httpStatus'] : '' ?>
			</p>
			<?php if (empty($travelCountryRegionsResult['saved'])): ?>
				<p class="warn" style="margin:0 0 8px;">No se pudieron cargar regiones del pais seleccionado (<?= htmlspecialchars((string) ($travelCountryRegionsResult['reason'] ?? 'unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>).</p>
			<?php elseif (empty($availableRegionsByCountry)): ?>
				<p class="warn" style="margin:0 0 8px;">No se detectaron regiones para el pais <?= htmlspecialchars($selectedCountryName !== '' ? $selectedCountryName : ('#' . $selectedCountryId), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>.</p>
			<?php else: ?>
				<form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:10px;">
					<input type="hidden" name="action" value="travel-region-load">
					<input type="hidden" name="travel_country_select" value="<?= htmlspecialchars($selectedCountryId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
					<label for="travel_region_select"><strong>Region:</strong></label>
					<select id="travel_region_select" name="travel_region_select" required class="battle-action-button" style="padding:3px 6px;height:32px;min-width:260px;">
						<option value="">Selecciona una region...</option>
						<?php foreach ($availableRegionsByCountry as $regionItem): ?>
							<?php $regionItemId = trim((string) ($regionItem['id'] ?? '')); ?>
							<?php $regionItemName = trim((string) ($regionItem['name'] ?? '')); ?>
							<?php $regionItemOccupation = trim((string) ($regionItem['occupation'] ?? '')); ?>
							<?php if ($regionItemId === '' || $regionItemName === '') { continue; } ?>
							<option value="<?= htmlspecialchars($regionItemId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $regionItemId === $selectedRegionFromCountry ? 'selected' : '' ?>><?= htmlspecialchars($regionItemName . ' (#' . $regionItemId . ')' . ($regionItemOccupation !== '' ? ' - ' . $regionItemOccupation : ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
						<?php endforeach; ?>
					</select>
					<input type="hidden" name="region_url" id="region_url_from_country" value="<?= htmlspecialchars($selectedRegionFromCountry, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
					<button type="submit" class="train-button" onclick="document.getElementById('region_url_from_country').value = document.getElementById('travel_region_select').value;">Preparar viaje</button>
				</form>
			<?php endif; ?>
		<?php endif; ?>

		<?php if (!empty($regionTravelLookupResult['attempted'])): ?>
			<p class="section-meta" style="margin:8px 0 0;">
				Fuente: <?= htmlspecialchars((string) ($regionTravelLookupResult['regionUrl'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
				<?= isset($regionTravelLookupResult['httpStatus']) ? ' | HTTP ' . (int) $regionTravelLookupResult['httpStatus'] : '' ?>
			</p>
			<?php if (empty($regionTravelLookupResult['saved'])): ?>
				<p class="warn" style="margin:8px 0 0;">No se pudo preparar viaje para la region (<?= htmlspecialchars((string) ($regionTravelLookupResult['reason'] ?? 'unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>).</p>
			<?php else: ?>
				<div style="margin-top:10px;padding:10px;border:1px solid #d7e0ee;border-radius:8px;background:#f8fbff;">
					<p style="margin:0 0 4px;"><strong>Region:</strong> <?= htmlspecialchars($loadedRegionLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
					<p style="margin:0 0 4px;"><strong>Owner actual:</strong> <?= htmlspecialchars((string) (($regionTravelLookupResult['currentOwner'] ?? '') !== '' ? (string) $regionTravelLookupResult['currentOwner'] : '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
					<p style="margin:0;"><strong>Recurso:</strong> <?= htmlspecialchars((string) (($regionTravelLookupResult['resource'] ?? '') !== '' ? (string) $regionTravelLookupResult['resource'] : '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
				</div>
				<form class="js-async-action" method="post" style="margin-top:10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
					<input type="hidden" name="action" value="travel-now">
					<input type="hidden" name="travel_action_url" value="<?= htmlspecialchars((string) ($loadedTravelForm['actionUrl'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
					<input type="hidden" name="travel_country_id" value="<?= htmlspecialchars((string) ($loadedTravelForm['countryId'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
					<input type="hidden" name="travel_region_id" value="<?= htmlspecialchars((string) ($loadedTravelForm['regionId'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
					<input type="hidden" name="travel_redirect_url" value="<?= htmlspecialchars((string) ($loadedTravelForm['redirectUrl'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
					<input type="hidden" name="travel_destination" value="<?= htmlspecialchars($loadedRegionLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
					<span class="battle-side-hint">Destino: <?= htmlspecialchars($loadedRegionLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
					<select name="travel_ticket_quality" class="battle-action-button" style="padding:3px 6px;height:30px;">
						<?php if (!empty($loadedTicketOptions)): ?>
							<?php foreach ($loadedTicketOptions as $ticketOption): ?>
								<?php $ticketValue = (string) ($ticketOption['value'] ?? '1'); ?>
								<?php $ticketLabel = (string) ($ticketOption['label'] ?? ('Q' . $ticketValue)); ?>
								<option value="<?= htmlspecialchars($ticketValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($ticketLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
							<?php endforeach; ?>
						<?php else: ?>
							<option value="1">Q1</option>
						<?php endif; ?>
					</select>
					<button type="submit" class="train-button">Viajar</button>
				</form>
			<?php endif; ?>
		<?php endif; ?>
	</div>

	<div class="section-panel">
		<h2 class="section-title">Batallas</h2>
		<p class="section-meta">
			Fuente: <?= htmlspecialchars((string) ($battlesResult['url'] ?? $battlesUrl), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
			<?= isset($battlesResult['httpStatus']) ? ' | HTTP ' . (int) $battlesResult['httpStatus'] : '' ?>
			<?= isset($battlesResult['bodyLength']) ? ' | HTML ' . number_format((int) $battlesResult['bodyLength']) . ' bytes' : '' ?>
			<?= isset($battlesResult['pagesScanned']) ? ' | Paginas: ' . (int) $battlesResult['pagesScanned'] : '' ?>
			<?= array_key_exists('practiceFound', $battlesResult) ? ' | Practice Battle: ' . (!empty($battlesResult['practiceFound']) ? 'SI' : 'NO') : '' ?>
		</p>

		<?php if (empty($battlesResult['attempted'])): ?>
			<p class="warn" style="margin: 0;">La consulta de batallas aun no fue ejecutada.</p>
		<?php elseif (empty($battlesResult['saved'])): ?>
			<p class="warn" style="margin: 0;">No se pudo cargar batallas (<?= htmlspecialchars((string) ($battlesResult['reason'] ?? 'unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>).</p>
		<?php elseif (empty($battlesResult['items']) || !is_array($battlesResult['items'])): ?>
			<p class="warn" style="margin: 0;">No se detectaron enlaces de batallas en el HTML recibido.</p>
		<?php else: ?>
			<div class="battles-cards">
				<?php foreach ($battlesResult['items'] as $battle): ?>
					<?php
					$battleTitleRaw = (string) ($battle['title'] ?? 'Battle');
					$battleMatchupText = (string) ($battle['matchupText'] ?? '');
					$battleCity = $battleTitleRaw;
					if (preg_match('/(?:battle|batalla)\s+(?:for|por)\s+(.+)$/iu', $battleTitleRaw, $cityMatch)) {
						$battleCity = trim((string) ($cityMatch[1] ?? $battleTitleRaw));
					}
					$battleRoundValue = (string) (($battle['fightRoundId'] ?? '') !== '' ? (string) $battle['fightRoundId'] : '1');
					$battleDetailsLoaded = !empty($battle['detailsLoaded']);
					$defenderCountry = (string) (($battle['countryA'] ?? '') !== '' ? (string) $battle['countryA'] : '-');
					$attackerCountry = (string) (($battle['countryB'] ?? '') !== '' ? (string) $battle['countryB'] : '-');
					$battleParticipantsLabel = 'Defensor: ' . $defenderCountry . ' | Atacante: ' . $attackerCountry;
					if ($battleMatchupText !== '') {
						$battleParticipantsLabel .= ' | ' . $battleMatchupText;
					}
					$canFightDefender = !empty($battle['canFightDefender']);
					$canFightAttacker = !empty($battle['canFightAttacker']);
					$canFightAnySide = !empty($battle['canFight']) && !empty($battle['fightRequestUrl']);
					$battleTypeLabel = (string) (($battle['battleTypeLabel'] ?? '') !== '' ? (string) $battle['battleTypeLabel'] : 'Tipo desconocido');
					$travelDefenderName = (string) (($battle['travelDefenderRegionName'] ?? '') !== '' ? (string) $battle['travelDefenderRegionName'] : '');
					$travelDefenderUrl = (string) ($battle['travelDefenderRegionUrl'] ?? '');
					$travelDefenderActionUrl = (string) ($battle['travelDefenderActionUrl'] ?? '');
					$travelDefenderCountryId = (string) ($battle['travelDefenderCountryId'] ?? '');
					$travelDefenderRegionId = (string) ($battle['travelDefenderRegionId'] ?? '');
					$travelDefenderRedirectUrl = (string) ($battle['travelDefenderRedirectUrl'] ?? '');
					$travelDefenderTicketOptions = is_array($battle['travelDefenderTicketOptions'] ?? null) ? $battle['travelDefenderTicketOptions'] : [];
					$travelAttackerName = (string) (($battle['travelAttackerRegionName'] ?? '') !== '' ? (string) $battle['travelAttackerRegionName'] : '');
					$travelAttackerUrl = (string) ($battle['travelAttackerRegionUrl'] ?? '');
					$travelAttackerActionUrl = (string) ($battle['travelAttackerActionUrl'] ?? '');
					$travelAttackerCountryId = (string) ($battle['travelAttackerCountryId'] ?? '');
					$travelAttackerRegionId = (string) ($battle['travelAttackerRegionId'] ?? '');
					$travelAttackerRedirectUrl = (string) ($battle['travelAttackerRedirectUrl'] ?? '');
					$travelAttackerTicketOptions = is_array($battle['travelAttackerTicketOptions'] ?? null) ? $battle['travelAttackerTicketOptions'] : [];
					$weaponQ1 = (string) ($battle['weaponQ1'] ?? '');
					$weaponQ5 = (string) ($battle['weaponQ5'] ?? '');
					$weaponQ1Label = 'Q1' . ($weaponQ1 !== '' ? ' (' . $weaponQ1 . ')' : '');
					$weaponQ5Label = 'Q5' . ($weaponQ5 !== '' ? ' (' . $weaponQ5 . ')' : '');
					?>
					<div class="battle-card">
						<div class="battle-card-header">
							<h3 class="battle-city"><?= htmlspecialchars($battleCity, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h3>
						</div>

						<?php if (!$battleDetailsLoaded): ?>
							<form class="js-async-action" method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:0 0 8px;">
								<input type="hidden" name="action" value="battle-inspect">
								<input type="hidden" name="battle_page_url" value="<?= htmlspecialchars((string) ($battle['url'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
								<input type="hidden" name="battle_title" value="<?= htmlspecialchars($battleTitleRaw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
								<button type="submit" class="train-button">Cargar batalla</button>
							</form>
							<div class="battle-lanes">
								<div class="battle-lane left">
									<span class="battle-lane-role">Defensor</span>
									<div class="battle-lane-country"><?= htmlspecialchars($defenderCountry, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
								</div>

								<div class="battle-lane right">
									<span class="battle-lane-role">Atacante</span>
									<div class="battle-lane-country"><?= htmlspecialchars($attackerCountry, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
								</div>
							</div>
						<?php endif; ?>

						<?php if ($battleDetailsLoaded): ?>
						<div class="battle-lanes">
							<div class="battle-lane left">
								<span class="battle-lane-role">Defensor</span>
								<div class="battle-lane-country"><?= htmlspecialchars($defenderCountry, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
								<?php if ($canFightAnySide && $canFightDefender): ?>
									<form class="battle-side-form js-async-action" method="post">
										<input type="hidden" name="action" value="battle-fight-request">
										<input type="hidden" name="battle_action_url" value="<?= htmlspecialchars((string) $battle['fightRequestUrl'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
										<input type="hidden" name="battle_page_url" value="<?= htmlspecialchars((string) ($battle['url'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
										<input type="hidden" name="battle_title" value="<?= htmlspecialchars($battleTitleRaw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
										<input type="hidden" name="battle_round_id" value="<?= htmlspecialchars($battleRoundValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
										<input type="hidden" name="battle_side" value="defender">
										<input type="hidden" name="fight_ip" value="<?= htmlspecialchars((string) ($battle['fightIp'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
										<input type="hidden" name="fight_serverName" value="<?= htmlspecialchars((string) ($battle['fightServerName'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
										<input type="hidden" name="fight_citizenId" value="<?= htmlspecialchars((string) ($battle['fightCitizenId'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
										<input type="hidden" name="fight_myCitizenship" value="<?= htmlspecialchars((string) ($battle['fightMyCitizenship'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
										<input type="hidden" name="fight_citizenRegion" value="<?= htmlspecialchars((string) ($battle['fightCitizenRegion'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
										<input type="hidden" name="fight_securityHash" value="<?= htmlspecialchars((string) ($battle['fightSecurityHash'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
										<input type="hidden" name="fight_mousePattern" value="<?= htmlspecialchars((string) ($battle['fightMousePattern'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
										<input type="hidden" name="fight_gameDay" value="<?= htmlspecialchars((string) ($battle['fightGameDay'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
										<select name="battle_weapon_quality" class="battle-action-button" style="padding:3px 6px;height:28px;">
											<option value="0" selected>Q0</option>
											<option value="1"><?= htmlspecialchars($weaponQ1Label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
											<option value="5"><?= htmlspecialchars($weaponQ5Label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
										</select>
										<button type="submit" name="battle_value" value="Normal" class="battle-action-button">Hit</button>
										<button type="submit" name="battle_value" value="Berserk" class="battle-action-button">Berserk</button>
									</form>
								<?php else: ?>
									<div class="battle-side-hint">No disponible para pegar por defensor.</div>
									<?php if ($travelDefenderActionUrl !== '' && $travelDefenderCountryId !== '' && $travelDefenderRegionId !== ''): ?>
										<form class="battle-side-form js-async-action" method="post">
											<input type="hidden" name="action" value="travel-now">
											<input type="hidden" name="travel_action_url" value="<?= htmlspecialchars($travelDefenderActionUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
											<input type="hidden" name="travel_country_id" value="<?= htmlspecialchars($travelDefenderCountryId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
											<input type="hidden" name="travel_region_id" value="<?= htmlspecialchars($travelDefenderRegionId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
											<input type="hidden" name="travel_redirect_url" value="<?= htmlspecialchars($travelDefenderRedirectUrl !== '' ? $travelDefenderRedirectUrl : ('region.html?id=' . $travelDefenderRegionId), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
											<input type="hidden" name="travel_destination" value="<?= htmlspecialchars($travelDefenderName !== '' ? $travelDefenderName : 'Region objetivo', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
											<span class="battle-side-hint">Viajar a <?= htmlspecialchars($travelDefenderName !== '' ? $travelDefenderName : 'Region objetivo', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
											<select name="travel_ticket_quality" class="battle-action-button" style="padding:3px 6px;height:28px;">
												<?php if (!empty($travelDefenderTicketOptions)): ?>
													<?php foreach ($travelDefenderTicketOptions as $ticketOption): ?>
														<?php $ticketValue = (string) ($ticketOption['value'] ?? '1'); ?>
														<?php $ticketLabel = (string) ($ticketOption['label'] ?? ('Q' . $ticketValue)); ?>
														<option value="<?= htmlspecialchars($ticketValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($ticketLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
													<?php endforeach; ?>
												<?php else: ?>
													<option value="1">Q1</option>
												<?php endif; ?>
											</select>
											<button type="submit" class="battle-action-button">Viajar</button>
										</form>
									<?php elseif ($travelDefenderUrl !== ''): ?>
										<div class="battle-side-hint">Viajar a: <a class="battle-link" href="<?= htmlspecialchars($travelDefenderUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($travelDefenderName !== '' ? $travelDefenderName : 'Region objetivo', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a></div>
									<?php endif; ?>
								<?php endif; ?>
							</div>

							<div class="battle-lane right">
								<span class="battle-lane-role">Atacante</span>
								<div class="battle-lane-country"><?= htmlspecialchars($attackerCountry, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
								<?php if ($canFightAnySide && $canFightAttacker): ?>
									<form class="battle-side-form js-async-action" method="post">
										<input type="hidden" name="action" value="battle-fight-request">
										<input type="hidden" name="battle_action_url" value="<?= htmlspecialchars((string) $battle['fightRequestUrl'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
										<input type="hidden" name="battle_page_url" value="<?= htmlspecialchars((string) ($battle['url'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
										<input type="hidden" name="battle_title" value="<?= htmlspecialchars($battleTitleRaw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
										<input type="hidden" name="battle_round_id" value="<?= htmlspecialchars($battleRoundValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
										<input type="hidden" name="battle_side" value="attacker">
										<input type="hidden" name="fight_ip" value="<?= htmlspecialchars((string) ($battle['fightIp'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
										<input type="hidden" name="fight_serverName" value="<?= htmlspecialchars((string) ($battle['fightServerName'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
										<input type="hidden" name="fight_citizenId" value="<?= htmlspecialchars((string) ($battle['fightCitizenId'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
										<input type="hidden" name="fight_myCitizenship" value="<?= htmlspecialchars((string) ($battle['fightMyCitizenship'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
										<input type="hidden" name="fight_citizenRegion" value="<?= htmlspecialchars((string) ($battle['fightCitizenRegion'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
										<input type="hidden" name="fight_securityHash" value="<?= htmlspecialchars((string) ($battle['fightSecurityHash'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
										<input type="hidden" name="fight_mousePattern" value="<?= htmlspecialchars((string) ($battle['fightMousePattern'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
										<input type="hidden" name="fight_gameDay" value="<?= htmlspecialchars((string) ($battle['fightGameDay'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
										<span class="battle-side-hint">Q arma</span>
										<select name="battle_weapon_quality" class="battle-action-button" style="padding:3px 6px;height:28px;">
											<option value="0" selected>Q0</option>
											<option value="1"><?= htmlspecialchars($weaponQ1Label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
											<option value="5"><?= htmlspecialchars($weaponQ5Label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
										</select>
										<button type="submit" name="battle_value" value="Normal" class="battle-action-button">Hit</button>
										<button type="submit" name="battle_value" value="Berserk" class="battle-action-button">Berserk</button>
									</form>
								<?php else: ?>
									<div class="battle-side-hint">No disponible para pegar por atacante.</div>
									<?php if ($travelAttackerActionUrl !== '' && $travelAttackerCountryId !== '' && $travelAttackerRegionId !== ''): ?>
										<form class="battle-side-form js-async-action" method="post">
											<input type="hidden" name="action" value="travel-now">
											<input type="hidden" name="travel_action_url" value="<?= htmlspecialchars($travelAttackerActionUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
											<input type="hidden" name="travel_country_id" value="<?= htmlspecialchars($travelAttackerCountryId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
											<input type="hidden" name="travel_region_id" value="<?= htmlspecialchars($travelAttackerRegionId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
											<input type="hidden" name="travel_redirect_url" value="<?= htmlspecialchars($travelAttackerRedirectUrl !== '' ? $travelAttackerRedirectUrl : ('region.html?id=' . $travelAttackerRegionId), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
											<input type="hidden" name="travel_destination" value="<?= htmlspecialchars($travelAttackerName !== '' ? $travelAttackerName : 'Region atacante', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
											<span class="battle-side-hint">Viajar a <?= htmlspecialchars($travelAttackerName !== '' ? $travelAttackerName : 'Region atacante', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
											<select name="travel_ticket_quality" class="battle-action-button" style="padding:3px 6px;height:28px;">
												<?php if (!empty($travelAttackerTicketOptions)): ?>
													<?php foreach ($travelAttackerTicketOptions as $ticketOption): ?>
														<?php $ticketValue = (string) ($ticketOption['value'] ?? '1'); ?>
														<?php $ticketLabel = (string) ($ticketOption['label'] ?? ('Q' . $ticketValue)); ?>
														<option value="<?= htmlspecialchars($ticketValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($ticketLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
													<?php endforeach; ?>
												<?php else: ?>
													<option value="1">Q1</option>
												<?php endif; ?>
											</select>
											<button type="submit" class="battle-action-button">Viajar</button>
										</form>
									<?php elseif ($travelAttackerUrl !== ''): ?>
										<div class="battle-side-hint">Viajar a: <a class="battle-link" href="<?= htmlspecialchars($travelAttackerUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($travelAttackerName !== '' ? $travelAttackerName : 'Region atacante', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a></div>
									<?php else: ?>
										<div class="battle-side-hint">No se detecto region vecina controlada por el atacante.</div>
									<?php endif; ?>
								<?php endif; ?>
							</div>
						</div>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>

	<details class="debug-panel">
		<summary>Debug HTML received (<?= number_format($bodyLength) ?> bytes)</summary>
		<pre><?= htmlspecialchars($safeBody, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></pre>
	</details>

	<script>
	(function () {
		"use strict";

		function refreshBattlesPanel() {
			return fetch(window.location.href, {
				method: 'GET',
				headers: {
					'Accept': 'text/html'
				}
			}).then(function (response) {
				if (!response.ok) {
					throw new Error('HTTP ' + response.status);
				}
				return response.text();
			}).then(function (html) {
				var parser = new DOMParser();
				var doc = parser.parseFromString(html, 'text/html');
				var nextPanel = doc.getElementById('battles-panel');
				var currentPanel = document.getElementById('battles-panel');
				if (!nextPanel || !currentPanel) {
					throw new Error('No se encontro panel de batallas para actualizar.');
				}

				currentPanel.replaceWith(nextPanel);
			});
		}

		function ensureToastContainer() {
			var existing = document.querySelector('.action-toast-container');
			if (existing) return existing;
			var container = document.createElement('div');
			container.className = 'action-toast-container';
			document.body.appendChild(container);
			return container;
		}

		function showToast(message, isError) {
			if (!message) return;
			var container = ensureToastContainer();
			var toast = document.createElement('div');
			toast.className = 'action-toast ' + (isError ? 'error' : 'success');
			toast.textContent = message;
			container.appendChild(toast);
			requestAnimationFrame(function () {
				toast.classList.add('show');
			});

			setTimeout(function () {
				toast.classList.remove('show');
				setTimeout(function () {
					if (toast.parentNode) toast.parentNode.removeChild(toast);
				}, 220);
			}, isError ? 4500 : 2400);
		}

		document.addEventListener('click', function (event) {
			var target = event.target;
			if (!(target instanceof Element)) {
				return;
			}

			var submitButton = target.closest('button[type="submit"], input[type="submit"]');
			if (!(submitButton instanceof HTMLElement)) {
				return;
			}

			var form = submitButton.form;
			if (!(form instanceof HTMLFormElement) || !form.classList.contains('js-async-action')) {
				return;
			}

			var submitName = submitButton.getAttribute('name') || '';
			if (submitName !== '') {
				form.setAttribute('data-last-submit-name', submitName);
				form.setAttribute('data-last-submit-value', submitButton.getAttribute('value') || '');
			}
		});

		document.addEventListener('submit', function (event) {
			var form = event.target;
			if (!(form instanceof HTMLFormElement) || !form.classList.contains('js-async-action')) {
				return;
			}

			event.preventDefault();
			var submitButtons = form.querySelectorAll('button[type="submit"], input[type="submit"]');
			submitButtons.forEach(function (btn) {
				btn.disabled = true;
			});

			var formData = new FormData(form);
			var submitter = event.submitter;
			if (submitter && submitter.name) {
				formData.set(submitter.name, submitter.value || '');
			} else {
				var fallbackName = form.getAttribute('data-last-submit-name') || '';
				if (fallbackName !== '') {
					formData.set(fallbackName, form.getAttribute('data-last-submit-value') || '');
				}
			}
			formData.set('async', '1');

			fetch(form.getAttribute('action') || window.location.href, {
				method: 'POST',
				body: formData,
				headers: {
					'X-Requested-With': 'XMLHttpRequest',
					'Accept': 'application/json'
				}
			}).then(function (response) {
				if (!response.ok) {
					throw new Error('HTTP ' + response.status);
				}
				return response.json();
			}).then(function (data) {
				var isError = !data || !data.success;
				if (data && typeof data.energy === 'string' && data.energy !== '') {
					var energyNode = document.getElementById('panelEnergyValue');
					if (energyNode) {
						energyNode.textContent = data.energy;
					}
				}
				if (isError || (data && data.notify)) {
					showToast((data && data.message) ? data.message : 'Accion procesada con estado desconocido.', isError);
				}
				if (!isError && data && data.refreshBattlesSection) {
					return refreshBattlesPanel().catch(function (error) {
						showToast('No se pudo refrescar batallas: ' + (error && error.message ? error.message : 'desconocido'), true);
					});
				}
				if (!isError && data && (data.reloadBattles || data.reloadPage)) {
					window.setTimeout(function () {
						window.location.reload();
					}, 350);
				}
			}).catch(function (error) {
				showToast('Error asincrono: ' + (error && error.message ? error.message : 'desconocido'), true);
			}).finally(function () {
				submitButtons.forEach(function (btn) {
					btn.disabled = false;
				});
			});
		});
	})();
	</script>
</body>
</html>
