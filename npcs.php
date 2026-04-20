<?php
declare(strict_types=1);

session_start();

$regionsPath = __DIR__ . DIRECTORY_SEPARATOR . 'regions.json';
$data = [
    'countries' => [],
];
$loadError = '';

if (!is_file($regionsPath)) {
    $loadError = 'No se encontro regions.json en la carpeta simulador.';
} else {
    $raw = (string) file_get_contents($regionsPath);
    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (is_array($decoded)) {
            $data = $decoded;
        } else {
            $loadError = 'regions.json no contiene un objeto JSON valido.';
        }
    } catch (Throwable $e) {
        $loadError = 'No se pudo parsear regions.json: ' . $e->getMessage();
    }
}

$npcCachePath = __DIR__ . DIRECTORY_SEPARATOR . 'npc_statistics_cache.json';
$npcCache = [
    'regions' => [],
];

$ownedCompaniesCachePath = __DIR__ . DIRECTORY_SEPARATOR . 'owned_companies_cache.json';
$ownedCompaniesCache = [
    'syncedAt' => '',
    'syncedAtDisplay' => '',
    'sourceUrl' => 'https://vara.e-sim.org/business.html?businessType=COMPANIES',
    'companies' => [],
];

$credentialsPath = __DIR__ . DIRECTORY_SEPARATOR . 'credentials.php';
$credentials = [];
if (is_file($credentialsPath)) {
    $loadedCredentials = require $credentialsPath;
    if (is_array($loadedCredentials)) {
        $credentials = $loadedCredentials;
    }
}

$credentialsUserId = trim((string) ($credentials['userId'] ?? ''));
$credentialsMuId = trim((string) ($credentials['muId'] ?? ''));

$credentialsUsername = (string) ($credentials['username'] ?? '');
$cookieDir = __DIR__ . DIRECTORY_SEPARATOR . 'tmp';
$cookieSuffix = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower($credentialsUsername));
if ($cookieSuffix === null || $cookieSuffix === '') {
    $cookieSuffix = 'default';
}

$defaultCookieFileFromIndex = $cookieDir . DIRECTORY_SEPARATOR . 'vara_cookie_' . $cookieSuffix . '.txt';
$sharedIndexCookieFile = isset($_SESSION['curl_cookie_file']) && is_string($_SESSION['curl_cookie_file']) && $_SESSION['curl_cookie_file'] !== ''
    ? $_SESSION['curl_cookie_file']
    : $defaultCookieFileFromIndex;

$npcAuthPath = __DIR__ . DIRECTORY_SEPARATOR . 'npc_sync_auth.json';
$npcAuth = [
    'cookie' => '',
    'cookieFile' => '',
];

if (is_file($npcAuthPath)) {
    $rawNpcAuth = (string) file_get_contents($npcAuthPath);
    $decodedNpcAuth = json_decode($rawNpcAuth, true);
    if (is_array($decodedNpcAuth)) {
        $npcAuth['cookie'] = trim((string) ($decodedNpcAuth['cookie'] ?? ''));
        $npcAuth['cookieFile'] = trim((string) ($decodedNpcAuth['cookieFile'] ?? ''));
    }
}

if ($npcAuth['cookieFile'] === '' && is_string($sharedIndexCookieFile) && $sharedIndexCookieFile !== '') {
    $npcAuth['cookieFile'] = $sharedIndexCookieFile;
}

if (is_file($npcCachePath)) {
    $rawNpcCache = (string) file_get_contents($npcCachePath);
    $decodedNpcCache = json_decode($rawNpcCache, true);
    if (is_array($decodedNpcCache) && is_array($decodedNpcCache['regions'] ?? null)) {
        $npcCache = $decodedNpcCache;
    }
}

if (is_file($ownedCompaniesCachePath)) {
    $rawOwnedCompanies = (string) file_get_contents($ownedCompaniesCachePath);
    $decodedOwnedCompanies = json_decode($rawOwnedCompanies, true);
    if (is_array($decodedOwnedCompanies)) {
        $ownedCompaniesCache = array_merge($ownedCompaniesCache, $decodedOwnedCompanies);
        if (!is_array($ownedCompaniesCache['companies'] ?? null)) {
            $ownedCompaniesCache['companies'] = [];
        }
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (string) ($_POST['action'] ?? '') === 'save-sync-cookie') {
    $cookie = trim((string) ($_POST['syncCookie'] ?? ''));
    $npcAuth['cookie'] = $cookie;
    if ($npcAuth['cookieFile'] === '' && is_string($sharedIndexCookieFile) && $sharedIndexCookieFile !== '') {
        $npcAuth['cookieFile'] = $sharedIndexCookieFile;
    }

    $saved = @file_put_contents(
        $npcAuthPath,
        json_encode($npcAuth, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
    );

    $redirectParams = [];
    $redirectCountry = trim((string) ($_POST['country'] ?? ''));
    $redirectOwner = trim((string) ($_POST['owner'] ?? ''));
    $redirectSalaryRange = trim((string) ($_POST['salaryRange'] ?? ''));
    $redirectOwnedCompany = trim((string) ($_POST['ownedCompany'] ?? ''));
    $redirectResourceType = $_POST['resourceType'] ?? [];
    $redirectResourceTypes = [];

    if ($redirectCountry !== '') {
        $redirectParams['country'] = $redirectCountry;
    }
    if ($redirectOwner !== '') {
        $redirectParams['owner'] = $redirectOwner;
    }
    if ($redirectSalaryRange !== '') {
        $redirectParams['salaryRange'] = $redirectSalaryRange;
    }
    if ($redirectOwnedCompany !== '') {
        $redirectParams['ownedCompany'] = $redirectOwnedCompany;
    }
    if (is_array($redirectResourceType)) {
        foreach ($redirectResourceType as $candidateType) {
            $candidateType = trim((string) $candidateType);
            if ($candidateType !== '') {
                $redirectResourceTypes[$candidateType] = true;
            }
        }
    } else {
        $candidateType = trim((string) $redirectResourceType);
        if ($candidateType !== '') {
            $redirectResourceTypes[$candidateType] = true;
        }
    }
    if ($redirectResourceTypes !== []) {
        $redirectParams['resourceType'] = array_keys($redirectResourceTypes);
    }

    $redirectParams['syncStatus'] = $saved !== false ? 'ok' : 'error';
    $redirectParams['syncMessage'] = $saved !== false
        ? 'Cookie de sesion guardada para sincronizar NPCs.'
        : 'No se pudo guardar npc_sync_auth.json.';

    header('Location: npcs.php' . ($redirectParams !== [] ? ('?' . http_build_query($redirectParams)) : ''));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (string) ($_POST['action'] ?? '') === 'sync-owned-companies') {
    $redirectParams = [];
    $redirectCountry = trim((string) ($_POST['country'] ?? ''));
    $redirectOwner = trim((string) ($_POST['owner'] ?? ''));
    $redirectSalaryRange = trim((string) ($_POST['salaryRange'] ?? ''));
    $redirectOwnedCompany = trim((string) ($_POST['ownedCompany'] ?? ''));
    $redirectResourceType = $_POST['resourceType'] ?? [];
    $redirectResourceTypes = [];

    if ($redirectCountry !== '') {
        $redirectParams['country'] = $redirectCountry;
    }
    if ($redirectOwner !== '') {
        $redirectParams['owner'] = $redirectOwner;
    }
    if ($redirectSalaryRange !== '') {
        $redirectParams['salaryRange'] = $redirectSalaryRange;
    }
    if ($redirectOwnedCompany !== '') {
        $redirectParams['ownedCompany'] = $redirectOwnedCompany;
    }
    if (is_array($redirectResourceType)) {
        foreach ($redirectResourceType as $candidateType) {
            $candidateType = trim((string) $candidateType);
            if ($candidateType !== '') {
                $redirectResourceTypes[$candidateType] = true;
            }
        }
    } else {
        $candidateType = trim((string) $redirectResourceType);
        if ($candidateType !== '') {
            $redirectResourceTypes[$candidateType] = true;
        }
    }
    if ($redirectResourceTypes !== []) {
        $redirectParams['resourceType'] = array_keys($redirectResourceTypes);
    }

    $companiesUrl = 'https://vara.e-sim.org/business.html?businessType=COMPANIES';
    $effectiveCookie = (string) ($npcAuth['cookie'] ?? '');
    $effectiveCookieFile = (string) ($npcAuth['cookieFile'] ?? '');
    if ($effectiveCookieFile === '' && is_string($sharedIndexCookieFile) && $sharedIndexCookieFile !== '') {
        $effectiveCookieFile = $sharedIndexCookieFile;
    }

    $companySyncStatus = 'error';
    $companySyncMessage = 'No se pudo sincronizar empresas propias.';
    $companySyncCount = 0;

    $fetchResult = fetchHtmlFromUrl($companiesUrl, $effectiveCookie, $effectiveCookieFile);
    if (!empty($fetchResult['ok'])) {
        if (htmlLooksLikeNotLoggedIn((string) ($fetchResult['body'] ?? ''))) {
            $companySyncMessage = 'La respuesta indica que no hay sesion iniciada. Guarda cookie de sesion para sincronizar empresas.';
        } else {
            $companies = parseOwnedCompaniesHtml((string) ($fetchResult['body'] ?? ''));
            $companySyncCount = count($companies);
            $ownedCompaniesCache = [
                'syncedAt' => gmdate('c'),
                'syncedAtDisplay' => date('Y-m-d H:i:s'),
                'sourceUrl' => $companiesUrl,
                'companies' => $companies,
            ];

            $saved = @file_put_contents(
                $ownedCompaniesCachePath,
                json_encode($ownedCompaniesCache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
            );

            if ($saved !== false) {
                $companySyncStatus = 'ok';
                $companySyncMessage = 'Empresas sincronizadas correctamente.';
            } else {
                $companySyncMessage = 'No se pudo guardar owned_companies_cache.json.';
            }
        }
    } else {
        $status = (int) ($fetchResult['httpStatus'] ?? 0);
        $error = trim((string) ($fetchResult['error'] ?? ''));
        $companySyncMessage = 'Fallo al consultar business.html';
        if ($status > 0) {
            $companySyncMessage .= ' (HTTP ' . $status . ')';
        }
        if ($error !== '') {
            $companySyncMessage .= ': ' . $error;
        }
    }

    $redirectParams['companySyncStatus'] = $companySyncStatus;
    $redirectParams['companySyncCount'] = (string) $companySyncCount;
    $redirectParams['companySyncMessage'] = $companySyncMessage;

    header('Location: npcs.php' . ($redirectParams !== [] ? ('?' . http_build_query($redirectParams)) : ''));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (string) ($_POST['action'] ?? '') === 'sync-region-npcs') {
    $isAsyncRequest = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

    $regionId = trim((string) ($_POST['regionId'] ?? ''));
    $regionName = trim((string) ($_POST['regionName'] ?? ''));
    $regionUrl = trim((string) ($_POST['regionUrl'] ?? ''));
    $catalogOwner = trim((string) ($_POST['currentOwnerSnapshot'] ?? ''));
    if ($regionUrl === '' && $regionId !== '') {
        $regionUrl = 'https://vara.e-sim.org/region.html?id=' . rawurlencode($regionId);
    }

    $redirectParams = [];
    $redirectCountry = trim((string) ($_POST['country'] ?? ''));
    $redirectOwner = trim((string) ($_POST['owner'] ?? ''));
    $redirectSalaryRange = trim((string) ($_POST['salaryRange'] ?? ''));
    $redirectResourceType = $_POST['resourceType'] ?? [];
    $redirectResourceTypes = [];

    if ($redirectCountry !== '') {
        $redirectParams['country'] = $redirectCountry;
    }
    if ($redirectOwner !== '') {
        $redirectParams['owner'] = $redirectOwner;
    }
    if ($redirectSalaryRange !== '') {
        $redirectParams['salaryRange'] = $redirectSalaryRange;
    }
    if (is_array($redirectResourceType)) {
        foreach ($redirectResourceType as $candidateType) {
            $candidateType = trim((string) $candidateType);
            if ($candidateType !== '') {
                $redirectResourceTypes[$candidateType] = true;
            }
        }
    } else {
        $candidateType = trim((string) $redirectResourceType);
        if ($candidateType !== '') {
            $redirectResourceTypes[$candidateType] = true;
        }
    }
    if ($redirectResourceTypes !== []) {
        $redirectParams['resourceType'] = array_keys($redirectResourceTypes);
    }

    $syncStatus = 'error';
    $syncCount = 0;
    $syncMessage = 'No se pudo sincronizar la region.';

    if ($regionId !== '' && $regionUrl !== '') {
        $npcUrl = 'https://vara.e-sim.org/npcStatistics.html?regionId=' . rawurlencode($regionId);
        $effectiveCookie = (string) ($npcAuth['cookie'] ?? '');
        $effectiveCookieFile = (string) ($npcAuth['cookieFile'] ?? '');
        if ($effectiveCookieFile === '' && is_string($sharedIndexCookieFile) && $sharedIndexCookieFile !== '') {
            $effectiveCookieFile = $sharedIndexCookieFile;
        }

        $fetchResult = fetchHtmlFromUrl($npcUrl, $effectiveCookie, $effectiveCookieFile);
        if (!empty($fetchResult['ok'])) {
            if (htmlLooksLikeNotLoggedIn((string) ($fetchResult['body'] ?? ''))) {
                $syncMessage = 'La respuesta indica que no hay sesion iniciada. Guarda tu cookie de sesion para sincronizar NPCs autenticado.';
            } else {
            $parsedNpc = parseNpcStatisticsHtml((string) ($fetchResult['body'] ?? ''));
            $npcRows = is_array($parsedNpc['npcs'] ?? null) ? $parsedNpc['npcs'] : [];
            $npcRows = enrichNpcRowsWithWorkedStatus($npcRows, $effectiveCookie, $effectiveCookieFile, $credentialsUserId, $credentialsMuId);
            $syncCount = count($npcRows);
            $maxNpcSalaryInfo = getMaxNpcSalaryInfo($npcRows);
            $maxNpcSalaryValue = is_array($maxNpcSalaryInfo) && isset($maxNpcSalaryInfo['value']) && is_numeric($maxNpcSalaryInfo['value'])
                ? (float) $maxNpcSalaryInfo['value']
                : null;
            $maxNpcSalaryDisplay = $maxNpcSalaryValue !== null ? number_format($maxNpcSalaryValue, 2, '.', '') : '';
            $maxNpcSalaryCurrency = is_array($maxNpcSalaryInfo) ? trim((string) ($maxNpcSalaryInfo['currency'] ?? '')) : '';
            $maxNpcSalaryFlagClass = is_array($maxNpcSalaryInfo)
                ? sanitizeCssFlagClass((string) ($maxNpcSalaryInfo['flagClass'] ?? ''))
                : '';
            $ownerAtSync = trim((string) ($parsedNpc['regionOwner'] ?? ''));
            $ownerAtSyncFlagClass = trim((string) ($parsedNpc['regionOwnerFlagClass'] ?? ''));
            $ownerChanged = false;
            if ($catalogOwner !== '' && $ownerAtSync !== '') {
                $ownerChanged = normalizeMatchText($catalogOwner) !== normalizeMatchText($ownerAtSync);
            }

            $jobMarketUrl = 'https://vara.e-sim.org/jobMarket.html?regionId=' . rawurlencode($regionId);
            $jobOffers = [];
            $jobOfferCount = 0;
            $maxJobOfferInfo = null;
            $jobMarketWarning = '';

            $jobMarketFetch = fetchHtmlFromUrl($jobMarketUrl, $effectiveCookie, $effectiveCookieFile);
            if (!empty($jobMarketFetch['ok'])) {
                if (htmlLooksLikeNotLoggedIn((string) ($jobMarketFetch['body'] ?? ''))) {
                    $jobMarketWarning = 'No se pudo leer jobMarket por sesion no autenticada.';
                } else {
                    $jobOffers = parseJobMarketOffersHtml((string) ($jobMarketFetch['body'] ?? ''));
                    $jobOfferCount = count($jobOffers);
                    $maxJobOfferInfo = getMaxJobOfferInfo($jobOffers);
                }
            } else {
                $jobStatus = (int) ($jobMarketFetch['httpStatus'] ?? 0);
                $jobError = trim((string) ($jobMarketFetch['error'] ?? ''));
                $jobMarketWarning = 'No se pudo consultar jobMarket';
                if ($jobStatus > 0) {
                    $jobMarketWarning .= ' (HTTP ' . $jobStatus . ')';
                }
                if ($jobError !== '') {
                    $jobMarketWarning .= ': ' . $jobError;
                }
            }

            $maxJobOfferValue = is_array($maxJobOfferInfo) && isset($maxJobOfferInfo['value']) && is_numeric($maxJobOfferInfo['value'])
                ? (float) $maxJobOfferInfo['value']
                : null;
            $maxJobOfferCurrency = is_array($maxJobOfferInfo) ? trim((string) ($maxJobOfferInfo['currency'] ?? '')) : '';
            $maxJobOfferFlagClass = is_array($maxJobOfferInfo)
                ? sanitizeCssFlagClass((string) ($maxJobOfferInfo['flagClass'] ?? ''))
                : '';

            $npcCache['regions'][$regionId] = [
                'regionId' => $regionId,
                'regionName' => $regionName,
                'regionUrl' => $regionUrl,
                'npcUrl' => $npcUrl,
                'syncedAt' => gmdate('c'),
                'syncedAtDisplay' => date('Y-m-d H:i:s'),
                'npcCount' => $syncCount,
                'npcs' => $npcRows,
                'maxNpcSalaryValue' => $maxNpcSalaryValue,
                'maxNpcSalaryDisplay' => $maxNpcSalaryDisplay,
                'maxNpcSalaryCurrency' => $maxNpcSalaryCurrency,
                'maxNpcSalaryFlagClass' => $maxNpcSalaryFlagClass,
                'jobMarketUrl' => $jobMarketUrl,
                'jobOfferCount' => $jobOfferCount,
                'jobOffers' => $jobOffers,
                'maxJobOfferValue' => $maxJobOfferValue,
                'maxJobOfferCurrency' => $maxJobOfferCurrency,
                'maxJobOfferFlagClass' => $maxJobOfferFlagClass,
                'catalogOwner' => $catalogOwner,
                'ownerAtSync' => $ownerAtSync,
                'ownerAtSyncFlagClass' => $ownerAtSyncFlagClass,
                'ownerChanged' => $ownerChanged,
            ];

            $saved = @file_put_contents(
                $npcCachePath,
                json_encode($npcCache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
            );

            if ($saved !== false) {
                $syncStatus = 'ok';
                $syncMessage = 'Region sincronizada correctamente.';
                if ($ownerChanged) {
                    $syncMessage .= ' Aviso: el ocupante cambio de "' . $catalogOwner . '" a "' . $ownerAtSync . '".';
                } elseif ($catalogOwner !== '' && $ownerAtSync !== '') {
                    $syncMessage .= ' Ocupante validado: "' . $ownerAtSync . '".';
                }
                if ($jobOfferCount > 0) {
                    $syncMessage .= ' Ofertas laborales detectadas: ' . $jobOfferCount . '.';
                }
                if ($jobMarketWarning !== '') {
                    $syncMessage .= ' ' . $jobMarketWarning;
                }
            } else {
                $syncMessage = 'No se pudo guardar npc_statistics_cache.json.';
            }
            }
        } else {
            $status = (int) ($fetchResult['httpStatus'] ?? 0);
            $error = trim((string) ($fetchResult['error'] ?? ''));
            $syncMessage = 'Fallo al consultar npcStatistics';
            if ($status > 0) {
                $syncMessage .= ' (HTTP ' . $status . ')';
            }
            if ($error !== '') {
                $syncMessage .= ': ' . $error;
            }
        }
    } else {
        $syncMessage = 'Faltan datos de region para sincronizar.';
    }

    $redirectParams['syncStatus'] = $syncStatus;
    $redirectParams['syncRegionId'] = $regionId;
    $redirectParams['syncRegionName'] = $regionName;
    $redirectParams['syncCount'] = (string) $syncCount;
    $redirectParams['syncMessage'] = $syncMessage;

    if ($isAsyncRequest) {
        header('Content-Type: application/json; charset=UTF-8');
        $syncMeta = is_array($npcCache['regions'][$regionId] ?? null) ? $npcCache['regions'][$regionId] : null;
        $ownerAtSync = is_array($syncMeta) ? trim((string) ($syncMeta['ownerAtSync'] ?? '')) : '';
        $ownerAtSyncFlagClass = is_array($syncMeta)
            ? sanitizeCssFlagClass((string) ($syncMeta['ownerAtSyncFlagClass'] ?? ''))
            : '';
        $maxSalaryInfo = extractRegionMaxSalaryInfo($syncMeta);
        $maxJobOfferInfo = extractRegionMaxJobOfferInfo($syncMeta);
        $regionNpcOwnership = summarizeRegionNpcOwnership($syncMeta);
        echo json_encode([
            'ok' => $syncStatus === 'ok',
            'syncStatus' => $syncStatus,
            'syncRegionId' => $regionId,
            'syncRegionName' => $regionName,
            'syncCount' => $syncCount,
            'syncMessage' => $syncMessage,
            'syncHtml' => renderSyncMetaHtml($syncMeta),
            'ownerAtSync' => $ownerAtSync,
            'ownerAtSyncFlagClass' => $ownerAtSyncFlagClass,
            'ownerCellHtml' => renderCountryWithFlagHtml($ownerAtSync, $ownerAtSyncFlagClass),
            'salaryBaseCellHtml' => renderRegionBaseSalaryCellHtml($maxSalaryInfo),
            'regionNpcOwnedCount' => (int) ($regionNpcOwnership['ownedCount'] ?? 0),
            'regionNpcOwnedWorkedCount' => (int) ($regionNpcOwnership['ownedWorkedCount'] ?? 0),
            'regionNpcTotalCount' => (int) ($regionNpcOwnership['totalCount'] ?? 0),
            'regionNpcControl' => (bool) ($regionNpcOwnership['hasControl'] ?? false),
            'regionMaxSalaryValue' => isset($maxSalaryInfo['value']) && is_numeric($maxSalaryInfo['value']) ? (float) $maxSalaryInfo['value'] : null,
            'regionMaxJobOfferValue' => isset($maxJobOfferInfo['value']) && is_numeric($maxJobOfferInfo['value']) ? (float) $maxJobOfferInfo['value'] : null,
            'companyNpcListHtml' => renderCompanyRegionNpcsHtml($syncMeta),
            'companyJobOffersHtml' => renderCompanyRegionJobOffersHtml($syncMeta),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    header('Location: npcs.php' . ($redirectParams !== [] ? ('?' . http_build_query($redirectParams)) : ''));
    exit;
}

$countries = is_array($data['countries'] ?? null) ? (array) $data['countries'] : [];
$rows = [];
$countryOptions = [];
$resourceOptions = [];
$ownerOptions = [];

foreach ($countries as $country) {
    if (!is_array($country)) {
        continue;
    }

    $countryId = trim((string) ($country['id'] ?? ''));
    $countryName = trim((string) ($country['name'] ?? ''));
    if ($countryName !== '') {
        $countryOptions[$countryName] = true;
    }

    $regions = is_array($country['regions'] ?? null) ? (array) $country['regions'] : [];
    foreach ($regions as $region) {
        if (!is_array($region)) {
            continue;
        }

        $resource = trim((string) ($region['resource'] ?? ''));
        $currentOwner = trim((string) ($region['currentOwner'] ?? ''));
        if ($resource !== '') {
            $resourceMeta = parseResourceMeta($resource);
            $resourceType = (string) ($resourceMeta['type'] ?? '');
            if ($resourceType !== '') {
                $resourceOptions[$resourceType] = [
                    'iconUrl' => (string) ($resourceMeta['iconUrl'] ?? ''),
                ];
            }
        }
        if ($currentOwner !== '') {
            $ownerOptions[$currentOwner] = true;
        }

        $rows[] = [
            'countryId' => $countryId,
            'countryName' => $countryName,
            'id' => trim((string) ($region['id'] ?? '')),
            'name' => trim((string) ($region['name'] ?? '')),
            'hasResource' => (bool) ($region['hasResource'] ?? false),
            'resource' => $resource,
            'currentOwner' => $currentOwner,
            'url' => trim((string) ($region['url'] ?? '')),
        ];
    }
}

$rows = dedupeRegionRows($rows);

$regionCatalogById = [];
foreach ($rows as $row) {
    if (!is_array($row)) {
        continue;
    }

    $regionId = trim((string) ($row['id'] ?? ''));
    if ($regionId === '' || isset($regionCatalogById[$regionId])) {
        continue;
    }

    $regionCatalogById[$regionId] = $row;
}

$ownedCompanies = is_array($ownedCompaniesCache['companies'] ?? null) ? (array) $ownedCompaniesCache['companies'] : [];
$ownedCompaniesByRegion = groupOwnedCompaniesByRegion($ownedCompanies);

$selectedCountry = trim((string) ($_GET['country'] ?? ''));
$selectedResourceTypes = [];
$rawSelectedResourceTypes = $_GET['resourceType'] ?? [];
if (is_array($rawSelectedResourceTypes)) {
    foreach ($rawSelectedResourceTypes as $candidateType) {
        $candidateType = trim((string) $candidateType);
        if ($candidateType !== '') {
            $selectedResourceTypes[$candidateType] = true;
        }
    }
} else {
    $candidateType = trim((string) $rawSelectedResourceTypes);
    if ($candidateType !== '') {
        $selectedResourceTypes[$candidateType] = true;
    }
}
$selectedResourceTypes = array_keys($selectedResourceTypes);
$selectedOwner = trim((string) ($_GET['owner'] ?? ''));
$selectedSalaryRange = trim((string) ($_GET['salaryRange'] ?? ''));
$selectedOwnedCompany = trim((string) ($_GET['ownedCompany'] ?? ''));

$syncStatus = trim((string) ($_GET['syncStatus'] ?? ''));
$syncRegionId = trim((string) ($_GET['syncRegionId'] ?? ''));
$syncRegionName = trim((string) ($_GET['syncRegionName'] ?? ''));
$syncCount = (int) ($_GET['syncCount'] ?? 0);
$syncMessage = trim((string) ($_GET['syncMessage'] ?? ''));

$companySyncStatus = trim((string) ($_GET['companySyncStatus'] ?? ''));
$companySyncCount = (int) ($_GET['companySyncCount'] ?? 0);
$companySyncMessage = trim((string) ($_GET['companySyncMessage'] ?? ''));

$filteredRows = array_values(array_filter($rows, static function (array $row) use ($selectedCountry, $selectedResourceTypes, $selectedOwner, $selectedSalaryRange, $selectedOwnedCompany, $npcCache, $ownedCompaniesByRegion): bool {
    if ($selectedCountry !== '' && (string) ($row['countryName'] ?? '') !== $selectedCountry) {
        return false;
    }

    if ($selectedResourceTypes !== []) {
        $rowType = 'none';
        if ((string) ($row['resource'] ?? '') !== '') {
            $resourceMeta = parseResourceMeta((string) ($row['resource'] ?? ''));
            $rowType = (string) ($resourceMeta['type'] ?? '');
        }

        if (!in_array($rowType, $selectedResourceTypes, true)) {
            return false;
        }
    }

    if ($selectedOwner !== '' && $row['currentOwner'] !== $selectedOwner) {
        return false;
    }

    if ($selectedSalaryRange !== '') {
        $regionId = (string) ($row['id'] ?? '');
        $syncMeta = is_array($npcCache['regions'][$regionId] ?? null) ? $npcCache['regions'][$regionId] : null;
        $salaryValue = extractRegionMaxSalaryValue($syncMeta);
        if (!isSalaryInRange($salaryValue, $selectedSalaryRange)) {
            return false;
        }
    }

    if ($selectedOwnedCompany === 'yes') {
        $regionId = (string) ($row['id'] ?? '');
        $regionCompanies = is_array($ownedCompaniesByRegion[$regionId] ?? null) ? (array) $ownedCompaniesByRegion[$regionId] : [];
        if ($regionCompanies === []) {
            return false;
        }
    }

    return true;
}));

usort($filteredRows, static function (array $a, array $b): int {
    $ownerCmp = strcasecmp((string) $a['currentOwner'], (string) $b['currentOwner']);
    if ($ownerCmp !== 0) {
        return $ownerCmp;
    }

    $countryCmp = strcasecmp((string) $a['countryName'], (string) $b['countryName']);
    if ($countryCmp !== 0) {
        return $countryCmp;
    }

    return strcasecmp((string) $a['name'], (string) $b['name']);
});

$countryList = array_keys($countryOptions);
sort($countryList, SORT_NATURAL | SORT_FLAG_CASE);

$resourceTypeList = array_keys($resourceOptions);
sort($resourceTypeList, SORT_NATURAL | SORT_FLAG_CASE);

$ownerList = array_keys($ownerOptions);
sort($ownerList, SORT_NATURAL | SORT_FLAG_CASE);

$totalRows = count($rows);
$visibleRows = count($filteredRows);

function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function parseResourceMeta(string $resource): array
{
    $resource = trim($resource);
    if ($resource === '') {
        return [
            'label' => '',
            'quality' => '',
            'type' => '',
            'iconUrl' => '',
        ];
    }

    $normalized = strtolower($resource);
    $quality = '';
    if (str_contains($normalized, 'alto') || str_contains($normalized, 'high')) {
        $quality = 'high';
    } elseif (str_contains($normalized, 'medio') || str_contains($normalized, 'medium')) {
        $quality = 'medium';
    }

    $iconByType = [
        'iron' => 'https://vara.e-sim.org/cdn/static/img/productIcons/Rewards/iron.png',
        'grain' => 'https://vara.e-sim.org/cdn/static/img/productIcons/Rewards/grain.png',
        'oil' => 'https://vara.e-sim.org/cdn/static/img/productIcons/Rewards/oil.png',
        'stone' => 'https://vara.e-sim.org/cdn/static/img/productIcons/Rewards/stone.png',
        'wood' => 'https://vara.e-sim.org/cdn/static/img/productIcons/Rewards/wood.png',
        'minerals' => 'https://vara.e-sim.org/cdn/static/img/productIcons/Rewards/minerals.png',
    ];

    $typeMatched = '';
    $iconUrl = '';
    foreach ($iconByType as $type => $url) {
        if (str_contains($normalized, $type)) {
            $typeMatched = $type;
            $iconUrl = $url;
            break;
        }
    }

    return [
        'label' => $resource,
        'quality' => $quality,
        'type' => $typeMatched,
        'iconUrl' => $iconUrl,
    ];
}

function toFlagSuffixFromCountryName(string $countryName): string
{
    $resolved = resolveFlagSuffixFromCountryName($countryName);
    if ($resolved !== '') {
        return $resolved;
    }

    $name = trim($countryName);
    if ($name === '') {
        return '';
    }

    $name = preg_replace('/\s+/', '-', $name);
    $name = preg_replace('/[^A-Za-z0-9-]/', '-', (string) $name);
    $name = preg_replace('/-+/', '-', (string) $name);
    $name = trim((string) $name, '-');

    return $name;
}

function normalizeCountryNameKey(string $countryName): string
{
    $key = trim(mb_strtolower($countryName, 'UTF-8'));
    if ($key === '') {
        return '';
    }

    $key = str_replace(
        ['á', 'à', 'ä', 'â', 'ã', 'å', 'é', 'è', 'ë', 'ê', 'í', 'ì', 'ï', 'î', 'ó', 'ò', 'ö', 'ô', 'õ', 'ú', 'ù', 'ü', 'û', 'ñ', 'ç'],
        ['a', 'a', 'a', 'a', 'a', 'a', 'e', 'e', 'e', 'e', 'i', 'i', 'i', 'i', 'o', 'o', 'o', 'o', 'o', 'u', 'u', 'u', 'u', 'n', 'c'],
        $key
    );

    $key = preg_replace('/[\'"`\.\,\(\)\[\]]+/u', ' ', $key);
    $key = preg_replace('/\b(the|el|la|los|las|de|del|da|do|das|dos|of)\b/u', ' ', (string) $key);
    $key = preg_replace('/\s+/', ' ', (string) $key);

    return trim((string) $key);
}

function resolveFlagSuffixFromCountryName(string $countryName): string
{
    $key = normalizeCountryNameKey($countryName);
    if ($key === '') {
        return '';
    }

    static $aliases = [
        'estados unidos' => 'United-States',
        'usa' => 'United-States',
        'eeuu' => 'United-States',
        'u s a' => 'United-States',
        'united states' => 'United-States',
        'reino unido' => 'United-Kingdom',
        'gran bretana' => 'United-Kingdom',
        'great britain' => 'United-Kingdom',
        'united kingdom' => 'United-Kingdom',
        'inglaterra' => 'England',
        'espana' => 'Spain',
        'españa' => 'Spain',
        'alemania' => 'Germany',
        'deutschland' => 'Germany',
        'paises bajos' => 'Netherlands',
        'pais bajos' => 'Netherlands',
        'netherlands' => 'Netherlands',
        'holanda' => 'Netherlands',
        'corea del sur' => 'South-Korea',
        'corea sur' => 'South-Korea',
        'south korea' => 'South-Korea',
        'korea south' => 'South-Korea',
        'corea del norte' => 'North-Korea',
        'corea norte' => 'North-Korea',
        'north korea' => 'North-Korea',
        'korea north' => 'North-Korea',
        'republica checa' => 'Czech-Republic',
        'czech republic' => 'Czech-Republic',
        'chequia' => 'Czech-Republic',
        'ivory coast' => 'Cote-d-Ivoire',
        'cote divoire' => 'Cote-d-Ivoire',
        'costa de marfil' => 'Cote-d-Ivoire',
        'emiratos arabes unidos' => 'United-Arab-Emirates',
        'united arab emirates' => 'United-Arab-Emirates',
        'arabia saudita' => 'Saudi-Arabia',
        'saudi arabia' => 'Saudi-Arabia',
        'sudafrica' => 'South-Africa',
        'south africa' => 'South-Africa',
        'nueva zelanda' => 'New-Zealand',
        'new zealand' => 'New-Zealand',
        'bosnia y herzegovina' => 'Bosnia-and-Herzegovina',
        'bosnia and herzegovina' => 'Bosnia-and-Herzegovina',
        'macedonia del norte' => 'North-Macedonia',
        'north macedonia' => 'North-Macedonia',
        'republica dominicana' => 'Dominican-Republic',
        'dominican republic' => 'Dominican-Republic',
    ];

    return $aliases[$key] ?? '';
}

function flagClassFromCountryName(string $countryName): string
{
    $suffix = toFlagSuffixFromCountryName($countryName);
    if ($suffix === '') {
        return '';
    }

    return 'xflagsSmall-' . $suffix;
}

function renderCountryWithFlagHtml(string $countryName, string $preferredFlagClass = ''): string
{
    $countryName = trim($countryName);
    if ($countryName === '') {
        return '-';
    }

    $safePreferred = sanitizeCssFlagClass($preferredFlagClass);
    $flagClass = $safePreferred !== '' ? $safePreferred : sanitizeCssFlagClass(flagClassFromCountryName($countryName));

    ob_start();
    if ($flagClass !== '') {
        ?>
        <span class="resource-cell"><span class="xflagsSmall <?= esc($flagClass) ?>"></span><?= esc($countryName) ?></span>
        <?php
    } else {
        ?>
        <?= esc($countryName) ?>
        <?php
    }

    return trim((string) ob_get_clean());
}

function renderRegionBaseSalaryCellHtml(?array $salaryInfo): string
{
    if (!is_array($salaryInfo)) {
        return '-';
    }

    $value = isset($salaryInfo['value']) && is_numeric($salaryInfo['value']) ? (float) $salaryInfo['value'] : null;
    if ($value === null) {
        return '-';
    }

    $displayValue = number_format($value, 2, '.', '');
    $currency = trim((string) ($salaryInfo['currency'] ?? ''));
    $flagClass = sanitizeCssFlagClass((string) ($salaryInfo['flagClass'] ?? ''));

    ob_start();
    ?>
    <span class="resource-cell">
        <?php if ($flagClass !== ''): ?>
            <span class="xflagsSmall <?= esc($flagClass) ?>"></span>
        <?php endif; ?>
        <span><?= esc($displayValue) ?></span>
        <?php if ($currency !== ''): ?>
            <span><?= esc($currency) ?></span>
        <?php endif; ?>
    </span>
    <?php

    return trim((string) ob_get_clean());
}

function renderSyncMetaHtml(?array $syncMeta): string
{
    if ($syncMeta === null) {
        return '';
    }

    $npcCount = (int) ($syncMeta['npcCount'] ?? 0);
    $syncedAtDisplay = esc((string) ($syncMeta['syncedAtDisplay'] ?? ''));
    $syncedNpcs = is_array($syncMeta['npcs'] ?? null) ? $syncMeta['npcs'] : [];
    $catalogOwner = trim((string) ($syncMeta['catalogOwner'] ?? ''));
    $ownerAtSync = trim((string) ($syncMeta['ownerAtSync'] ?? ''));
    $ownerAtSyncFlagClass = sanitizeCssFlagClass((string) ($syncMeta['ownerAtSyncFlagClass'] ?? ''));
    $ownerChanged = (bool) ($syncMeta['ownerChanged'] ?? false);
    $maxNpcSalaryInfo = extractRegionMaxSalaryInfo($syncMeta);
    $maxNpcSalaryValue = is_array($maxNpcSalaryInfo) && isset($maxNpcSalaryInfo['value']) && is_numeric($maxNpcSalaryInfo['value'])
        ? (float) $maxNpcSalaryInfo['value']
        : null;
    $maxJobOfferInfo = extractRegionMaxJobOfferInfo($syncMeta);
    $maxJobOfferValue = is_array($maxJobOfferInfo) && isset($maxJobOfferInfo['value']) && is_numeric($maxJobOfferInfo['value'])
        ? (float) $maxJobOfferInfo['value']
        : null;
    $jobOfferCount = (int) ($syncMeta['jobOfferCount'] ?? 0);

    ob_start();
    ?>
    <div class="sync-meta">
        NPCs: <?= $npcCount ?><br>
        Ultima sync: <?= $syncedAtDisplay ?>
        <?php if ($maxNpcSalaryValue !== null): ?>
            <br>
            Sueldo base (max NPC): <?= renderRegionBaseSalaryCellHtml($maxNpcSalaryInfo) ?>
        <?php endif; ?>
        <?php if ($maxJobOfferValue !== null): ?>
            <br>
            Oferta laboral top (<?= $jobOfferCount ?>): <?= renderRegionBaseSalaryCellHtml($maxJobOfferInfo) ?>
            <?php if ($maxNpcSalaryValue !== null && $maxJobOfferValue > $maxNpcSalaryValue): ?>
                <br>
                <span class="sync-owner-status warning">Competencia activa: oferta top supera sueldo NPC max.</span>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($ownerAtSync !== '' || $catalogOwner !== ''): ?>
            <br>
            <span class="sync-owner-status <?= $ownerChanged ? 'warning' : 'ok' ?>">
                <?php if ($ownerAtSyncFlagClass !== ''): ?>
                    <span class="xflagsSmall <?= esc($ownerAtSyncFlagClass) ?>"></span>
                <?php endif; ?>
                <?php if ($ownerChanged): ?>
                    Ocupante cambio: <?= esc($catalogOwner !== '' ? $catalogOwner : '?') ?> -> <?= esc($ownerAtSync !== '' ? $ownerAtSync : '?') ?>
                <?php else: ?>
                    Ocupante validado: <?= esc($ownerAtSync !== '' ? $ownerAtSync : $catalogOwner) ?>
                <?php endif; ?>
            </span>
        <?php endif; ?>
    </div>
    <?php if ($syncedNpcs !== []): ?>
        <details class="sync-details">
            <summary>Ver NPCs sincronizados</summary>
            <ul class="sync-npc-list">
                <?php foreach ($syncedNpcs as $npcRow): ?>
                    <?php $salaryFlagClass = sanitizeCssFlagClass((string) ($npcRow['salaryFlagClass'] ?? '')); ?>
                    <?php $salaryCurrency = trim((string) ($npcRow['salaryCurrency'] ?? '')); ?>
                    <?php $salaryDisplay = formatNpcSalaryDisplay((string) ($npcRow['salary'] ?? '-'), $salaryCurrency); ?>
                    <?php $companyOwnedByUserOrMu = (bool) ($npcRow['companyOwnedByUserOrMu'] ?? false); ?>
                    <?php $companyNameText = (string) ($npcRow['company'] ?? 'Sin empresa'); ?>
                    <?php $workedTodayRaw = $npcRow['workedToday'] ?? null; ?>
                    <?php $workedDayLabel = trim((string) ($npcRow['workedDayLabel'] ?? '')); ?>
                    <?php $workedText = ''; ?>
                    <?php $workedClass = 'pending'; ?>
                    <?php $workedIcon = '&#128339;'; ?>
                    <?php if ($workedTodayRaw === true): ?>
                        <?php if ($companyOwnedByUserOrMu): ?>
                            <?php $workedText = ''; ?>
                            <?php $workedClass = 'worked'; ?>
                            <?php $workedIcon = '&#10003;'; ?>
                        <?php else: ?>
                            <?php $workedText = ''; ?>
                            <?php $workedClass = 'lost'; ?>
                            <?php $workedIcon = '&#10007;'; ?>
                        <?php endif; ?>
                    <?php elseif ($workedTodayRaw === false): ?>
                        <?php $workedText = ''; ?>
                        <?php $workedClass = 'pending'; ?>
                        <?php $workedIcon = '&#128339;'; ?>
                    <?php endif; ?>
                    <li>
                        <?= esc((string) ($npcRow['npc'] ?? 'NPC')) ?> |
                        <?php if ($companyOwnedByUserOrMu): ?>
                            <strong style="color:#1f6b31;"><?= esc($companyNameText) ?></strong>
                        <?php else: ?>
                            <?= esc($companyNameText) ?>
                        <?php endif; ?> |
                        Sueldo:
                        <?php if ($salaryFlagClass !== ''): ?>
                            <span class="xflagsSmall <?= esc($salaryFlagClass) ?>"></span>
                        <?php endif; ?>
                        <?= esc($salaryDisplay) ?>
                        |
                        <span class="npc-work-chip <?= esc($workedClass) ?>"><span class="icon"><?= $workedIcon ?></span><?= esc($workedText) ?></span><?= $workedDayLabel !== '' ? (' (' . esc($workedDayLabel) . ')') : '' ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </details>
    <?php endif; ?>
    <?php

    return trim((string) ob_get_clean());
}

function normalizeMatchText(string $value): string
{
    $value = trim(mb_strtolower($value, 'UTF-8'));
    $value = str_replace(['á', 'é', 'í', 'ó', 'ú', 'ñ'], ['a', 'e', 'i', 'o', 'u', 'n'], $value);
    return $value;
}

function sanitizeCssFlagClass(string $rawClass): string
{
    $rawClass = trim($rawClass);
    if ($rawClass === '') {
        return '';
    }

    if (!preg_match('/^xflagsSmall-[A-Za-z0-9-]+$/', $rawClass)) {
        return '';
    }

    return $rawClass;
}

function htmlLooksLikeNotLoggedIn(string $html): bool
{
    $rawLower = strtolower($html);
    $normalized = normalizeMatchText(strip_tags($html));
    if ($normalized === '') {
        return true;
    }

    if (str_contains($normalized, 'not logged in')) {
        return true;
    }

    if (str_contains($rawLower, 'name="password"') || str_contains($rawLower, 'type="password"')) {
        return true;
    }

    if (str_contains($rawLower, 'id="login"') || str_contains($normalized, 'not logged in')) {
        return true;
    }

    return false;
}

function buildCookieHeaderFromCookieFile(string $cookieFile): string
{
    if ($cookieFile === '' || !is_file($cookieFile)) {
        return '';
    }

    $lines = @file($cookieFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return '';
    }

    $pairs = [];
    $now = time();
    foreach ($lines as $line) {
        $line = trim((string) $line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $parts = explode("\t", $line);
        if (count($parts) < 7) {
            continue;
        }

        $expires = (int) $parts[4];
        $name = trim((string) $parts[5]);
        $value = trim((string) $parts[6]);

        if ($name === '' || $value === '') {
            continue;
        }
        if ($expires > 0 && $expires < $now) {
            continue;
        }

        $pairs[] = $name . '=' . $value;
    }

    return implode('; ', $pairs);
}

function fetchHtmlFromUrl(string $url, string $cookie = '', string $cookieFile = ''): array
{
    $result = [
        'ok' => false,
        'httpStatus' => 0,
        'body' => '',
        'error' => '',
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch !== false) {
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) NPCSync/1.0',
            ]);

            if ($cookie !== '') {
                curl_setopt($ch, CURLOPT_COOKIE, $cookie);
            }
            if ($cookieFile !== '' && is_file($cookieFile)) {
                curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
                curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
            }

            $body = curl_exec($ch);
            $error = curl_error($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $result['httpStatus'] = $status;
            $result['body'] = is_string($body) ? $body : '';
            $result['error'] = $error;
            $result['ok'] = $status >= 200 && $status < 400 && $result['body'] !== '';
            return $result;
        }
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 20,
            'ignore_errors' => true,
            'header' => (function () use ($cookie, $cookieFile): string {
                $fallbackCookie = $cookie;
                if ($fallbackCookie === '' && $cookieFile !== '') {
                    $fallbackCookie = buildCookieHeaderFromCookieFile($cookieFile);
                }

                return "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) NPCSync/1.0\r\n"
                    . ($fallbackCookie !== '' ? ('Cookie: ' . $fallbackCookie . "\r\n") : '');
            })(),
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    $result['body'] = is_string($body) ? $body : '';
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $headerLine) {
            if (preg_match('/^HTTP\/\d(?:\.\d)?\s+(\d{3})/i', (string) $headerLine, $m)) {
                $result['httpStatus'] = (int) $m[1];
                break;
            }
        }
    }

    $result['ok'] = $result['httpStatus'] >= 200 && $result['httpStatus'] < 400 && $result['body'] !== '';
    if (!$result['ok'] && $result['error'] === '') {
        $result['error'] = 'No se pudo obtener respuesta valida.';
    }

    return $result;
}

function parseNpcStatisticsHtml(string $html): array
{
    $npcs = [];
    $regionOwner = '';
    $regionOwnerFlagClass = '';
    if (trim($html) === '') {
        return [
            'npcs' => $npcs,
            'regionOwner' => $regionOwner,
            'regionOwnerFlagClass' => $regionOwnerFlagClass,
        ];
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $loaded = $dom->loadHTML($html);
    if (!$loaded) {
        return [
            'npcs' => $npcs,
            'regionOwner' => $regionOwner,
            'regionOwnerFlagClass' => $regionOwnerFlagClass,
        ];
    }

    $xpath = new DOMXPath($dom);
    $tables = $xpath->query('//table[.//tr[td]]');
    if (!$tables instanceof DOMNodeList || $tables->length === 0) {
        return [
            'npcs' => $npcs,
            'regionOwner' => $regionOwner,
            'regionOwnerFlagClass' => $regionOwnerFlagClass,
        ];
    }

    $regionOwnerNode = $xpath->query('(//h3//span[contains(@class, "countryNameTranslated")])[1]');
    if ($regionOwnerNode instanceof DOMNodeList && $regionOwnerNode->length > 0) {
        $node = $regionOwnerNode->item(0);
        if ($node instanceof DOMNode) {
            $regionOwner = trim((string) $node->textContent);
            $regionOwnerFlagClass = extractFirstFlagClassFromNode($xpath, $node->parentNode instanceof DOMNode ? $node->parentNode : $node);
        }
    }

    $bestTable = null;
    $bestRows = -1;
    $preferredTable = null;
    foreach ($tables as $tableNode) {
        $headerCells = $xpath->query('.//tr[1]/*[self::th or self::td]', $tableNode);
        $hasNpcHeader = false;
        $hasCompanyHeader = false;
        $hasSalaryHeader = false;
        if ($headerCells instanceof DOMNodeList) {
            foreach ($headerCells as $headerCell) {
                $headerText = normalizeMatchText((string) $headerCell->textContent);
                if (str_contains($headerText, 'npc') || str_contains($headerText, 'name') || str_contains($headerText, 'nombre')) {
                    $hasNpcHeader = true;
                }
                if (str_contains($headerText, 'company') || str_contains($headerText, 'empresa') || str_contains($headerText, 'employer')) {
                    $hasCompanyHeader = true;
                }
                if (str_contains($headerText, 'salary') || str_contains($headerText, 'sueldo') || str_contains($headerText, 'pay')) {
                    $hasSalaryHeader = true;
                }
            }
        }
        if ($hasNpcHeader && $hasCompanyHeader && $hasSalaryHeader) {
            $preferredTable = $tableNode;
            break;
        }

        $rowsCount = (int) $xpath->evaluate('count(.//tr[td])', $tableNode);
        if ($rowsCount > $bestRows) {
            $bestRows = $rowsCount;
            $bestTable = $tableNode;
        }
    }

    if ($preferredTable instanceof DOMNode) {
        $bestTable = $preferredTable;
    }

    if (!$bestTable instanceof DOMNode) {
        return [
            'npcs' => $npcs,
            'regionOwner' => $regionOwner,
            'regionOwnerFlagClass' => $regionOwnerFlagClass,
        ];
    }

    $headerCells = $xpath->query('.//tr[1]/*[self::th or self::td]', $bestTable);
    $headerMap = [];
    if ($headerCells instanceof DOMNodeList) {
        foreach ($headerCells as $i => $headerCell) {
            $headerMap[$i] = normalizeMatchText((string) $headerCell->textContent);
        }
    }

    $nameIdx = null;
    $companyIdx = null;
    $salaryIdx = null;
    foreach ($headerMap as $i => $headerText) {
        if ($nameIdx === null && (str_contains($headerText, 'npc') || str_contains($headerText, 'name') || str_contains($headerText, 'nombre'))) {
            $nameIdx = (int) $i;
        }
        if ($companyIdx === null && (str_contains($headerText, 'company') || str_contains($headerText, 'empresa') || str_contains($headerText, 'employer'))) {
            $companyIdx = (int) $i;
        }
        if ($salaryIdx === null && (str_contains($headerText, 'salary') || str_contains($headerText, 'sueldo') || str_contains($headerText, 'pay'))) {
            $salaryIdx = (int) $i;
        }
    }

    $rows = $xpath->query('.//tr[td]', $bestTable);
    if (!$rows instanceof DOMNodeList) {
        return [
            'npcs' => $npcs,
            'regionOwner' => $regionOwner,
            'regionOwnerFlagClass' => $regionOwnerFlagClass,
        ];
    }

    foreach ($rows as $row) {
        $cells = $xpath->query('./td', $row);
        if (!$cells instanceof DOMNodeList || $cells->length === 0) {
            continue;
        }

        $cellTexts = [];
        for ($i = 0; $i < $cells->length; $i++) {
            $cellTexts[$i] = trim((string) $cells->item($i)?->textContent);
        }

        $companyUrl = '';
        $companyName = '';
        $rowLinks = $xpath->query('.//a[contains(@href, "company.html")]', $row);
        if ($rowLinks instanceof DOMNodeList && $rowLinks->length > 0) {
            $companyLink = $rowLinks->item(0);
            if ($companyLink instanceof DOMElement) {
                $companyUrl = trim((string) $companyLink->getAttribute('href'));
                $companyName = trim((string) $companyLink->textContent);
            }
        }

        $npcName = '';
        if ($nameIdx !== null && array_key_exists($nameIdx, $cellTexts)) {
            $npcName = (string) $cellTexts[$nameIdx];
        }
        if ($npcName === '') {
            $npcName = (string) ($cellTexts[0] ?? '');
        }

        if ($companyIdx !== null && array_key_exists($companyIdx, $cellTexts) && $companyName === '') {
            $companyName = (string) $cellTexts[$companyIdx];
        }

        $salary = '';
        $salaryFlagClass = '';
        $salaryCurrency = '';
        if ($salaryIdx !== null && array_key_exists($salaryIdx, $cellTexts)) {
            $salary = (string) $cellTexts[$salaryIdx];
            $salaryCellNode = $cells->item($salaryIdx);
            if ($salaryCellNode instanceof DOMNode) {
                $salaryFlagClass = extractFirstFlagClassFromNode($xpath, $salaryCellNode);
                if (preg_match('/\b([A-Z]{2,4})\b/u', $salary, $salaryCurrencyMatch)) {
                    $salaryCurrency = (string) ($salaryCurrencyMatch[1] ?? '');
                }
            }
        }
        if ($salary === '') {
            foreach ($cellTexts as $cellText) {
                if (preg_match('/\d+[\.,]?\d*/', $cellText)) {
                    $salary = $cellText;
                    break;
                }
            }
        }

        if ($npcName === '' && $companyName === '' && $salary === '') {
            continue;
        }

        $npcs[] = [
            'npc' => $npcName,
            'company' => $companyName,
            'companyUrl' => $companyUrl,
            'salary' => $salary,
            'salaryFlagClass' => $salaryFlagClass,
            'salaryCurrency' => $salaryCurrency,
        ];
    }

    return [
        'npcs' => $npcs,
        'regionOwner' => $regionOwner,
        'regionOwnerFlagClass' => $regionOwnerFlagClass,
    ];
}

function toAbsoluteEsimUrl(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    if (preg_match('/^https?:\/\//i', $url)) {
        return $url;
    }

    if (str_starts_with($url, '//')) {
        return 'https:' . $url;
    }

    if (str_starts_with($url, '/')) {
        return 'https://vara.e-sim.org' . $url;
    }

    return 'https://vara.e-sim.org/' . ltrim($url, '/');
}

function parseCompanyWorkedStatusFromHtml(string $html): array
{
    $workers = [];
    $latestDayLabel = '';
    $ownerProfileId = '';
    $ownerMuId = '';

    if (trim($html) === '') {
        return [
            'workers' => $workers,
            'latestDayLabel' => $latestDayLabel,
        ];
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $loaded = $dom->loadHTML($html);
    if (!$loaded) {
        return [
            'workers' => $workers,
            'latestDayLabel' => $latestDayLabel,
        ];
    }

    $xpath = new DOMXPath($dom);

    $ownerProfileNode = $xpath->query('(//*[contains(@class, "employer")]//a[contains(@onmousedown, "profile?id=") or contains(@href, "profile.html?id=")])[1]');
    if ($ownerProfileNode instanceof DOMNodeList && $ownerProfileNode->length > 0) {
        $ownerLinkNode = $ownerProfileNode->item(0);
        if ($ownerLinkNode instanceof DOMElement) {
            $ownerRef = trim((string) $ownerLinkNode->getAttribute('onmousedown'));
            if ($ownerRef === '') {
                $ownerRef = trim((string) $ownerLinkNode->getAttribute('href'));
            }
            if ($ownerRef !== '' && preg_match('/profile(?:\.html)?\?id=(\d+)/i', $ownerRef, $ownerMatches) === 1) {
                $ownerProfileId = (string) ($ownerMatches[1] ?? '');
            }
        }
    }

    $ownerMuNode = $xpath->query('(//*[contains(@class, "employer")]//a[contains(@href, "militaryUnit.html?id=") or contains(@onmousedown, "militaryUnit.html?id=")])[1]');
    if ($ownerMuNode instanceof DOMNodeList && $ownerMuNode->length > 0) {
        $ownerMuLinkNode = $ownerMuNode->item(0);
        if ($ownerMuLinkNode instanceof DOMElement) {
            $ownerMuRef = trim((string) $ownerMuLinkNode->getAttribute('href'));
            if ($ownerMuRef === '') {
                $ownerMuRef = trim((string) $ownerMuLinkNode->getAttribute('onmousedown'));
            }
            if ($ownerMuRef !== '' && preg_match('/militaryUnit\.html\?id=(\d+)/i', $ownerMuRef, $ownerMuMatches) === 1) {
                $ownerMuId = (string) ($ownerMuMatches[1] ?? '');
            }
        }
    }

    $tableNode = $xpath->query('//*[@id="workResultsDisplay"]//table[@id="productivityTable"][1]');
    if (!$tableNode instanceof DOMNodeList || $tableNode->length === 0) {
        return [
            'workers' => $workers,
            'latestDayLabel' => $latestDayLabel,
            'ownerProfileId' => $ownerProfileId,
            'ownerMuId' => $ownerMuId,
        ];
    }

    $table = $tableNode->item(0);
    if (!$table instanceof DOMNode) {
        return [
            'workers' => $workers,
            'latestDayLabel' => $latestDayLabel,
            'ownerProfileId' => $ownerProfileId,
            'ownerMuId' => $ownerMuId,
        ];
    }

    $headerCells = $xpath->query('./tr[1]/td | ./tbody/tr[1]/td', $table);
    $latestColumnIndex = null;
    if ($headerCells instanceof DOMNodeList && $headerCells->length >= 3) {
        $latestColumnIndex = $headerCells->length - 1;
        $latestHeaderNode = $headerCells->item($latestColumnIndex);
        if ($latestHeaderNode instanceof DOMNode) {
            $latestDayLabel = trim((string) $latestHeaderNode->textContent);
        }
    }

    if ($latestColumnIndex === null || $latestColumnIndex < 2) {
        return [
            'workers' => $workers,
            'latestDayLabel' => $latestDayLabel,
            'ownerProfileId' => $ownerProfileId,
            'ownerMuId' => $ownerMuId,
        ];
    }

    $rows = $xpath->query('./tr[position()>1] | ./tbody/tr[position()>1]', $table);
    if (!$rows instanceof DOMNodeList || $rows->length === 0) {
        return [
            'workers' => $workers,
            'latestDayLabel' => $latestDayLabel,
            'ownerProfileId' => $ownerProfileId,
            'ownerMuId' => $ownerMuId,
        ];
    }

    foreach ($rows as $row) {
        if (!$row instanceof DOMNode) {
            continue;
        }

        $cells = $xpath->query('./td', $row);
        if (!$cells instanceof DOMNodeList || $cells->length <= $latestColumnIndex) {
            continue;
        }

        $nameCell = $cells->item(0);
        $latestCell = $cells->item($latestColumnIndex);
        if (!$nameCell instanceof DOMNode || !$latestCell instanceof DOMNode) {
            continue;
        }

        $nameNode = $xpath->query('.//*[contains(@class, "citizenName")][1]', $nameCell);
        $workerName = '';
        if ($nameNode instanceof DOMNodeList && $nameNode->length > 0) {
            $node = $nameNode->item(0);
            if ($node instanceof DOMNode) {
                $workerName = trim((string) $node->textContent);
            }
        }
        if ($workerName === '') {
            $workerName = trim((string) $nameCell->textContent);
            if ($workerName !== '') {
                $workerName = trim(preg_replace('/\s+/u', ' ', $workerName) ?? '');
            }
        }
        if ($workerName === '') {
            continue;
        }

        $latestText = trim((string) $latestCell->textContent);
        $crossNode = $xpath->query('.//img[contains(@src, "cross-icon")]', $latestCell);
        $hasCrossIcon = $crossNode instanceof DOMNodeList && $crossNode->length > 0;

        $workedToday = false;
        if (!$hasCrossIcon) {
            if ($latestText !== '' && preg_match('/\d/u', $latestText)) {
                $workedToday = true;
            } else {
                $divNodes = $xpath->query('./div', $latestCell);
                if ($divNodes instanceof DOMNodeList && $divNodes->length > 0) {
                    $workedToday = true;
                }
            }
        }

        $workers[normalizeMatchText($workerName)] = $workedToday;
    }

    return [
        'workers' => $workers,
        'latestDayLabel' => $latestDayLabel,
        'ownerProfileId' => $ownerProfileId,
        'ownerMuId' => $ownerMuId,
    ];
}

function enrichNpcRowsWithWorkedStatus(array $npcRows, string $cookie = '', string $cookieFile = '', string $credentialsUserId = '', string $credentialsMuId = ''): array
{
    if ($npcRows === []) {
        return $npcRows;
    }

    $companyStatusByUrl = [];
    $uniqueCompanyUrls = [];

    foreach ($npcRows as $npcRow) {
        if (!is_array($npcRow)) {
            continue;
        }
        $companyUrl = toAbsoluteEsimUrl((string) ($npcRow['companyUrl'] ?? ''));
        if ($companyUrl !== '') {
            $uniqueCompanyUrls[$companyUrl] = true;
        }
    }

    foreach (array_keys($uniqueCompanyUrls) as $companyUrl) {
        $fetch = fetchHtmlFromUrl($companyUrl, $cookie, $cookieFile);
        if (!empty($fetch['ok'])) {
            $companyStatusByUrl[$companyUrl] = parseCompanyWorkedStatusFromHtml((string) ($fetch['body'] ?? ''));
        } else {
            $companyStatusByUrl[$companyUrl] = [
                'workers' => [],
                'latestDayLabel' => '',
            ];
        }
    }

    foreach ($npcRows as $index => $npcRow) {
        if (!is_array($npcRow)) {
            continue;
        }

        $companyUrl = toAbsoluteEsimUrl((string) ($npcRow['companyUrl'] ?? ''));
        $npcName = trim((string) ($npcRow['npc'] ?? ''));
        $workedToday = null;
        $workedDayLabel = '';
        $companyOwnerProfileId = '';
        $companyOwnerMuId = '';
        $companyOwnedByUser = false;
        $companyOwnedByMu = false;

        if ($companyUrl !== '' && $npcName !== '' && is_array($companyStatusByUrl[$companyUrl] ?? null)) {
            $companyStatus = $companyStatusByUrl[$companyUrl];
            $workedDayLabel = trim((string) ($companyStatus['latestDayLabel'] ?? ''));
            $companyOwnerProfileId = trim((string) ($companyStatus['ownerProfileId'] ?? ''));
            $companyOwnerMuId = trim((string) ($companyStatus['ownerMuId'] ?? ''));
            $workers = is_array($companyStatus['workers'] ?? null) ? $companyStatus['workers'] : [];
            $npcKey = normalizeMatchText($npcName);
            if ($npcKey !== '' && array_key_exists($npcKey, $workers)) {
                $workedToday = (bool) $workers[$npcKey];
            }

            $companyOwnedByUser = $credentialsUserId !== '' && $companyOwnerProfileId !== '' && $credentialsUserId === $companyOwnerProfileId;
            $companyOwnedByMu = $credentialsMuId !== '' && $companyOwnerMuId !== '' && $credentialsMuId === $companyOwnerMuId;
        }

        $npcRows[$index]['companyUrl'] = $companyUrl;
        $npcRows[$index]['workedToday'] = $workedToday;
        $npcRows[$index]['workedDayLabel'] = $workedDayLabel;
        $npcRows[$index]['companyOwnerProfileId'] = $companyOwnerProfileId;
        $npcRows[$index]['companyOwnerMuId'] = $companyOwnerMuId;
        $npcRows[$index]['companyOwnedByUser'] = $companyOwnedByUser;
        $npcRows[$index]['companyOwnedByMu'] = $companyOwnedByMu;
        $npcRows[$index]['companyOwnedByUserOrMu'] = $companyOwnedByUser || $companyOwnedByMu;
    }

    return $npcRows;
}

function extractFirstFlagClassFromNode(DOMXPath $xpath, DOMNode $contextNode): string
{
    $nodes = $xpath->query('.//*[contains(@class, "xflagsSmall-")]', $contextNode);
    if (!$nodes instanceof DOMNodeList || $nodes->length === 0) {
        return '';
    }

    foreach ($nodes as $node) {
        if (!$node instanceof DOMElement) {
            continue;
        }
        $classAttr = trim((string) $node->getAttribute('class'));
        if ($classAttr === '') {
            continue;
        }
        if (preg_match('/\bxflagsSmall-([A-Za-z0-9-]+)\b/', $classAttr, $matches)) {
            return 'xflagsSmall-' . (string) $matches[1];
        }
    }

    return '';
}

function normalizeSalaryNumberString(string $raw): ?float
{
    $candidate = trim($raw);
    if ($candidate === '') {
        return null;
    }

    if (preg_match('/-?\d[\d\.,]*/u', $candidate, $m) !== 1) {
        return null;
    }

    $num = (string) $m[0];
    $hasDot = str_contains($num, '.');
    $hasComma = str_contains($num, ',');

    if ($hasDot && $hasComma) {
        $lastDot = strrpos($num, '.');
        $lastComma = strrpos($num, ',');
        if ($lastDot !== false && $lastComma !== false) {
            if ($lastDot > $lastComma) {
                $num = str_replace(',', '', $num);
            } else {
                $num = str_replace('.', '', $num);
                $num = str_replace(',', '.', $num);
            }
        }
    } elseif ($hasComma && !$hasDot) {
        $num = str_replace(',', '.', $num);
    }

    if (!is_numeric($num)) {
        return null;
    }

    return (float) $num;
}

function formatNpcSalaryDisplay(string $salary, string $currency): string
{
    $salary = trim($salary);
    $currency = trim($currency);

    if ($salary === '') {
        $salary = '-';
    }
    if ($currency === '') {
        return $salary;
    }

    if (preg_match('/\b' . preg_quote($currency, '/') . '\b/i', $salary) === 1) {
        return $salary;
    }

    return trim($salary . ' ' . $currency);
}

function normalizeCountryComparableValue(string $countryName): string
{
    $countryName = trim($countryName);
    if ($countryName === '') {
        return '';
    }

    $suffix = resolveFlagSuffixFromCountryName($countryName);
    if ($suffix !== '') {
        return normalizeMatchText(str_replace('-', ' ', $suffix));
    }

    return normalizeCountryNameKey($countryName);
}

function buildRegionRowKey(array $row): string
{
    $regionId = trim((string) ($row['id'] ?? ''));
    if ($regionId !== '') {
        return 'id:' . $regionId;
    }

    $regionUrl = trim((string) ($row['url'] ?? ''));
    if ($regionUrl !== '') {
        return 'url:' . normalizeMatchText($regionUrl);
    }

    return 'name:' . normalizeMatchText((string) ($row['name'] ?? ''));
}

function isOccupiedRow(array $row): bool
{
    $country = normalizeCountryComparableValue((string) ($row['countryName'] ?? ''));
    $owner = normalizeCountryComparableValue((string) ($row['currentOwner'] ?? ''));
    if ($country === '' || $owner === '') {
        return false;
    }

    return $country !== $owner;
}

function dedupeRegionRows(array $rows): array
{
    if ($rows === []) {
        return $rows;
    }

    $byRegion = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $key = buildRegionRowKey($row);
        if (!isset($byRegion[$key])) {
            $byRegion[$key] = [];
        }
        $byRegion[$key][] = $row;
    }

    $result = [];
    foreach ($byRegion as $group) {
        if (!is_array($group) || $group === []) {
            continue;
        }

        if (count($group) === 1) {
            $result[] = $group[0];
            continue;
        }

        $uniqueByPair = [];
        foreach ($group as $candidate) {
            $countryNorm = normalizeCountryComparableValue((string) ($candidate['countryName'] ?? ''));
            $ownerNorm = normalizeCountryComparableValue((string) ($candidate['currentOwner'] ?? ''));
            $pairKey = $countryNorm . '|' . $ownerNorm;
            if (!isset($uniqueByPair[$pairKey])) {
                $uniqueByPair[$pairKey] = $candidate;
            }
        }
        $uniqueRows = array_values($uniqueByPair);

        $occupiedRows = array_values(array_filter($uniqueRows, static function (array $candidate): bool {
            return isOccupiedRow($candidate);
        }));

        if ($occupiedRows !== []) {
            $ownerTokens = [];
            foreach ($uniqueRows as $candidate) {
                $ownerToken = normalizeCountryComparableValue((string) ($candidate['currentOwner'] ?? ''));
                if ($ownerToken !== '') {
                    $ownerTokens[$ownerToken] = true;
                }
            }

            $preferredOccupied = null;
            foreach ($occupiedRows as $candidate) {
                $countryToken = normalizeCountryComparableValue((string) ($candidate['countryName'] ?? ''));
                if ($countryToken === '' || !isset($ownerTokens[$countryToken])) {
                    $preferredOccupied = $candidate;
                    break;
                }
            }

            $result[] = is_array($preferredOccupied) ? $preferredOccupied : $occupiedRows[0];
            continue;
        }

        $result[] = $uniqueRows[0];
    }

    return $result;
}

function getMaxNpcSalaryValue(array $npcRows): ?float
{
    $info = getMaxNpcSalaryInfo($npcRows);
    if (!is_array($info) || !isset($info['value']) || !is_numeric($info['value'])) {
        return null;
    }

    return (float) $info['value'];
}

function getMaxNpcSalaryInfo(array $npcRows): ?array
{
    $maxInfo = null;
    foreach ($npcRows as $npcRow) {
        if (!is_array($npcRow)) {
            continue;
        }
        $value = normalizeSalaryNumberString((string) ($npcRow['salary'] ?? ''));
        if ($value === null) {
            continue;
        }

        if ($maxInfo === null || $value > (float) $maxInfo['value']) {
            $maxInfo = [
                'value' => $value,
                'currency' => trim((string) ($npcRow['salaryCurrency'] ?? '')),
                'flagClass' => sanitizeCssFlagClass((string) ($npcRow['salaryFlagClass'] ?? '')),
            ];
        }
    }

    return $maxInfo;
}

function extractRegionMaxSalaryInfo(?array $syncMeta): ?array
{
    if (!is_array($syncMeta)) {
        return null;
    }

    $stored = $syncMeta['maxNpcSalaryValue'] ?? null;
    if (is_numeric($stored)) {
        return [
            'value' => (float) $stored,
            'currency' => trim((string) ($syncMeta['maxNpcSalaryCurrency'] ?? '')),
            'flagClass' => sanitizeCssFlagClass((string) ($syncMeta['maxNpcSalaryFlagClass'] ?? '')),
        ];
    }

    $syncedNpcs = is_array($syncMeta['npcs'] ?? null) ? $syncMeta['npcs'] : [];
    return getMaxNpcSalaryInfo($syncedNpcs);
}

function extractRegionMaxSalaryValue(?array $syncMeta): ?float
{
    $info = extractRegionMaxSalaryInfo($syncMeta);
    if (!is_array($info) || !isset($info['value']) || !is_numeric($info['value'])) {
        return null;
    }

    return (float) $info['value'];
}

function parseJobMarketOffersHtml(string $html): array
{
    $offers = [];
    if (trim($html) === '') {
        return $offers;
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $loaded = $dom->loadHTML($html);
    if (!$loaded) {
        return $offers;
    }

    $xpath = new DOMXPath($dom);
    $rows = $xpath->query('//table[.//tr[td]]//tr[td]');
    if (!$rows instanceof DOMNodeList || $rows->length === 0) {
        return $offers;
    }

    foreach ($rows as $row) {
        if (!$row instanceof DOMNode) {
            continue;
        }

        $rowText = trim((string) $row->textContent);
        if ($rowText === '') {
            continue;
        }

        $companyUrl = '';
        $companyName = '';
        $companyLinkNodes = $xpath->query('.//a[contains(@href, "company.html")][1]', $row);
        if ($companyLinkNodes instanceof DOMNodeList && $companyLinkNodes->length > 0) {
            $companyLink = $companyLinkNodes->item(0);
            if ($companyLink instanceof DOMElement) {
                $companyUrl = toAbsoluteEsimUrl((string) $companyLink->getAttribute('href'));
                $companyName = trim((string) $companyLink->textContent);
            }
        }

        $salary = '';
        $salaryCurrency = '';
        $salaryFlagClass = '';
        $salaryValue = null;

        $cells = $xpath->query('./td', $row);
        if ($cells instanceof DOMNodeList) {
            foreach ($cells as $cell) {
                if (!$cell instanceof DOMNode) {
                    continue;
                }

                $cellText = trim((string) $cell->textContent);
                if ($cellText === '') {
                    continue;
                }

                $candidateValue = normalizeSalaryNumberString($cellText);
                if ($candidateValue === null) {
                    continue;
                }

                $salary = $cellText;
                $salaryValue = $candidateValue;
                $salaryFlagClass = extractFirstFlagClassFromNode($xpath, $cell);
                if (preg_match('/\b([A-Z]{2,4})\b/u', $cellText, $currencyMatch) === 1) {
                    $salaryCurrency = (string) ($currencyMatch[1] ?? '');
                }
                break;
            }
        }

        if ($salaryValue === null && $companyName === '') {
            continue;
        }

        $offers[] = [
            'companyName' => $companyName,
            'companyUrl' => $companyUrl,
            'salary' => $salary,
            'salaryCurrency' => $salaryCurrency,
            'salaryFlagClass' => $salaryFlagClass,
            'salaryValue' => $salaryValue,
            'rowText' => $rowText,
        ];
    }

    return $offers;
}

function getMaxJobOfferInfo(array $offers): ?array
{
    $maxInfo = null;
    foreach ($offers as $offer) {
        if (!is_array($offer)) {
            continue;
        }

        $valueRaw = $offer['salaryValue'] ?? null;
        $value = is_numeric($valueRaw) ? (float) $valueRaw : normalizeSalaryNumberString((string) ($offer['salary'] ?? ''));
        if ($value === null) {
            continue;
        }

        if ($maxInfo === null || $value > (float) $maxInfo['value']) {
            $maxInfo = [
                'value' => $value,
                'currency' => trim((string) ($offer['salaryCurrency'] ?? '')),
                'flagClass' => sanitizeCssFlagClass((string) ($offer['salaryFlagClass'] ?? '')),
            ];
        }
    }

    return $maxInfo;
}

function extractRegionMaxJobOfferInfo(?array $syncMeta): ?array
{
    if (!is_array($syncMeta)) {
        return null;
    }

    $stored = $syncMeta['maxJobOfferValue'] ?? null;
    if (is_numeric($stored)) {
        return [
            'value' => (float) $stored,
            'currency' => trim((string) ($syncMeta['maxJobOfferCurrency'] ?? '')),
            'flagClass' => sanitizeCssFlagClass((string) ($syncMeta['maxJobOfferFlagClass'] ?? '')),
        ];
    }

    $offers = is_array($syncMeta['jobOffers'] ?? null) ? $syncMeta['jobOffers'] : [];
    return getMaxJobOfferInfo($offers);
}

function parseOwnedCompaniesHtml(string $html): array
{
    $items = [];
    if (trim($html) === '') {
        return $items;
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $loaded = $dom->loadHTML($html);
    if (!$loaded) {
        return $items;
    }

    $xpath = new DOMXPath($dom);
    $rows = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " company-row ") and contains(concat(" ", normalize-space(@class), " "), " ownedCompaniesGrid ")]');
    if (!$rows instanceof DOMNodeList || $rows->length === 0) {
        return $items;
    }

    $seen = [];
    foreach ($rows as $row) {
        if (!$row instanceof DOMElement) {
            continue;
        }

        $companyLinkNode = $xpath->query('.//a[contains(@href, "company.html?id=")][1]', $row);
        $companyLink = $companyLinkNode instanceof DOMNodeList && $companyLinkNode->length > 0 ? $companyLinkNode->item(0) : null;
        if (!$companyLink instanceof DOMElement) {
            continue;
        }

        $companyHref = trim((string) $companyLink->getAttribute('href'));
        $companyUrl = toAbsoluteEsimUrl($companyHref);
        if ($companyUrl === '' || preg_match('/company\.html\?id=(\d+)/i', $companyUrl, $companyMatches) !== 1) {
            continue;
        }
        $companyId = (string) ($companyMatches[1] ?? '');

        $regionLinkNode = $xpath->query('.//a[contains(@class, "regionLink") and contains(@href, "region.html?id=")][1]', $row);
        $regionLink = $regionLinkNode instanceof DOMNodeList && $regionLinkNode->length > 0 ? $regionLinkNode->item(0) : null;
        $regionId = '';
        $regionUrl = '';
        $regionName = '';
        if ($regionLink instanceof DOMElement) {
            $regionHref = trim((string) $regionLink->getAttribute('href'));
            $regionUrl = toAbsoluteEsimUrl($regionHref);
            $regionName = trim((string) $regionLink->textContent);
            if ($regionUrl !== '' && preg_match('/region\.html\?id=(\d+)/i', $regionUrl, $regionMatches) === 1) {
                $regionId = (string) ($regionMatches[1] ?? '');
            }
        }

        $companyNameNode = $xpath->query('.//span[contains(@class, "companyName")][1]', $row);
        $companyName = '';
        if ($companyNameNode instanceof DOMNodeList && $companyNameNode->length > 0) {
            $candidateName = $companyNameNode->item(0);
            if ($candidateName instanceof DOMNode) {
                $companyName = trim((string) $candidateName->textContent);
            }
        }
        if ($companyName === '') {
            $companyName = trim((string) $companyLink->textContent);
        }

        $countryNameNode = $xpath->query('.//span[contains(@class, "countryNameTranslated")][1]', $row);
        $countryName = '';
        if ($countryNameNode instanceof DOMNodeList && $countryNameNode->length > 0) {
            $candidateCountry = $countryNameNode->item(0);
            if ($candidateCountry instanceof DOMNode) {
                $countryName = trim((string) $candidateCountry->textContent);
            }
        }

        $productNode = $xpath->query('.//img[contains(@class, "newProduct")][1]', $row);
        $productImg = $productNode instanceof DOMNodeList && $productNode->length > 0 ? $productNode->item(0) : null;
        $productTitle = '';
        $productImageUrl = '';
        if ($productImg instanceof DOMElement) {
            $productTitle = trim((string) $productImg->getAttribute('title'));
            $productImageUrl = toAbsoluteEsimUrl(trim((string) $productImg->getAttribute('src')));
        }

        $businessType = '';
        $classAttr = trim((string) $row->getAttribute('class'));
        if ($classAttr !== '' && preg_match('/\b([A-Z0-9_]+_BUSINESS)\b/', $classAttr, $typeMatches) === 1) {
            $businessType = (string) ($typeMatches[1] ?? '');
        }

        $dedupeKey = $companyId !== '' ? ('company:' . $companyId) : ('url:' . $companyUrl);
        if (isset($seen[$dedupeKey])) {
            continue;
        }
        $seen[$dedupeKey] = true;

        $items[] = [
            'companyId' => $companyId,
            'companyName' => $companyName,
            'companyUrl' => $companyUrl,
            'regionId' => $regionId,
            'regionName' => $regionName,
            'regionUrl' => $regionUrl,
            'countryName' => $countryName,
            'productTitle' => $productTitle,
            'productImageUrl' => $productImageUrl,
            'businessType' => $businessType,
        ];
    }

    return $items;
}

function groupOwnedCompaniesByRegion(array $companies): array
{
    $grouped = [];
    foreach ($companies as $company) {
        if (!is_array($company)) {
            continue;
        }

        $regionId = trim((string) ($company['regionId'] ?? ''));
        if ($regionId === '') {
            continue;
        }

        if (!isset($grouped[$regionId])) {
            $grouped[$regionId] = [];
        }
        $grouped[$regionId][] = $company;
    }

    return $grouped;
}

function buildOwnedCompanyNpcCoverage(?array $syncMeta): array
{
    $coverage = [];
    if (!is_array($syncMeta)) {
        return $coverage;
    }

    $syncedNpcs = is_array($syncMeta['npcs'] ?? null) ? $syncMeta['npcs'] : [];
    foreach ($syncedNpcs as $npcRow) {
        if (!is_array($npcRow)) {
            continue;
        }

        if (!(bool) ($npcRow['companyOwnedByUserOrMu'] ?? false)) {
            continue;
        }

        $npcCompanyUrl = toAbsoluteEsimUrl((string) ($npcRow['companyUrl'] ?? ''));
        $npcCompanyName = trim((string) ($npcRow['company'] ?? ''));
        $companyKey = $npcCompanyUrl !== ''
            ? ('url:' . normalizeMatchText($npcCompanyUrl))
            : ('name:' . normalizeMatchText($npcCompanyName));
        if ($companyKey === 'url:' || $companyKey === 'name:') {
            continue;
        }

        if (!isset($coverage[$companyKey])) {
            $coverage[$companyKey] = [
                'total' => 0,
                'worked' => 0,
            ];
        }

        $coverage[$companyKey]['total']++;
        if (($npcRow['workedToday'] ?? null) === true) {
            $coverage[$companyKey]['worked']++;
        }
    }

    return $coverage;
}

function summarizeRegionNpcOwnership(?array $syncMeta): array
{
    $summary = [
        'totalCount' => 0,
        'ownedCount' => 0,
        'ownedWorkedCount' => 0,
        'hasControl' => false,
    ];

    if (!is_array($syncMeta)) {
        return $summary;
    }

    $syncedNpcs = is_array($syncMeta['npcs'] ?? null) ? $syncMeta['npcs'] : [];
    foreach ($syncedNpcs as $npcRow) {
        if (!is_array($npcRow)) {
            continue;
        }

        $summary['totalCount']++;

        if ((bool) ($npcRow['companyOwnedByUserOrMu'] ?? false)) {
            $summary['ownedCount']++;
            if (($npcRow['workedToday'] ?? null) === true) {
                $summary['ownedWorkedCount']++;
            }
        }
    }

    $summary['hasControl'] = $summary['ownedCount'] > 0;
    return $summary;
}

function renderCompanyRegionNpcsHtml(?array $syncMeta): string
{
    $companyRegionNpcs = is_array($syncMeta['npcs'] ?? null) ? array_slice((array) $syncMeta['npcs'], 0, 3) : [];

    ob_start();
    if ($companyRegionNpcs !== []) {
        ?>
        <strong>NPCs de la region:</strong>
        <ul>
            <?php foreach ($companyRegionNpcs as $npcRow): ?>
                <?php if (!is_array($npcRow)) { continue; } ?>
                <?php $npcName = trim((string) ($npcRow['npc'] ?? 'NPC')); ?>
                <?php $npcCompany = trim((string) ($npcRow['company'] ?? 'Sin empresa')); ?>
                <?php $npcSalary = trim((string) ($npcRow['salary'] ?? '-')); ?>
                <?php $npcSalaryCurrency = trim((string) ($npcRow['salaryCurrency'] ?? '')); ?>
                <?php $npcSalaryDisplay = formatNpcSalaryDisplay($npcSalary, $npcSalaryCurrency); ?>
                <?php $npcOwnedCompany = (bool) ($npcRow['companyOwnedByUserOrMu'] ?? false); ?>
                <?php $npcWorkedRaw = $npcRow['workedToday'] ?? null; ?>
                <?php $npcWorkedText = ''; ?>
                <?php $npcWorkedClass = 'pending'; ?>
                <?php $npcWorkedIcon = '&#128339;'; ?>
                <?php if ($npcWorkedRaw === true): ?>
                    <?php if ($npcOwnedCompany): ?>
                        <?php $npcWorkedText = ''; ?>
                        <?php $npcWorkedClass = 'worked'; ?>
                        <?php $npcWorkedIcon = '&#10003;'; ?>
                    <?php else: ?>
                        <?php $npcWorkedText = ''; ?>
                        <?php $npcWorkedClass = 'lost'; ?>
                        <?php $npcWorkedIcon = '&#10007;'; ?>
                    <?php endif; ?>
                <?php elseif ($npcWorkedRaw === false): ?>
                    <?php $npcWorkedText = ''; ?>
                    <?php $npcWorkedClass = 'pending'; ?>
                    <?php $npcWorkedIcon = '&#128339;'; ?>
                <?php endif; ?>
                <li>
                    <?php if ($npcOwnedCompany): ?>
                        <strong><?= esc($npcName !== '' ? $npcName : 'NPC') ?></strong>
                    <?php else: ?>
                        <?= esc($npcName !== '' ? $npcName : 'NPC') ?>
                    <?php endif; ?>
                    | <?= esc($npcCompany !== '' ? $npcCompany : 'Sin empresa') ?>
                    | <?= esc($npcSalaryDisplay) ?>
                    | <span class="npc-work-chip <?= esc($npcWorkedClass) ?>"><span class="icon"><?= $npcWorkedIcon ?></span><?= esc($npcWorkedText) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php
    } else {
        ?>
        <span style="color:var(--muted);">Sin sync NPC de la region.</span>
        <?php
    }

    return trim((string) ob_get_clean());
}

function renderCompanyRegionJobOffersHtml(?array $syncMeta): string
{
    $jobOffers = is_array($syncMeta['jobOffers'] ?? null) ? (array) $syncMeta['jobOffers'] : [];
    $jobOfferCount = (int) ($syncMeta['jobOfferCount'] ?? count($jobOffers));
    $offersToShow = array_slice($jobOffers, 0, 5);

    ob_start();
    if ($offersToShow !== []) {
        ?>
        <strong>Ofertas de trabajo de la region (<?= $jobOfferCount ?>):</strong>
        <ul>
            <?php foreach ($offersToShow as $offer): ?>
                <?php if (!is_array($offer)) { continue; } ?>
                <?php $offerCompanyName = trim((string) ($offer['companyName'] ?? 'Empresa')); ?>
                <?php $offerCompanyUrl = trim((string) ($offer['companyUrl'] ?? '')); ?>
                <?php $offerSalary = trim((string) ($offer['salary'] ?? '')); ?>
                <?php $offerCurrency = trim((string) ($offer['salaryCurrency'] ?? '')); ?>
                <?php $offerSalaryDisplay = formatNpcSalaryDisplay($offerSalary, $offerCurrency); ?>
                <?php $offerFlagClass = sanitizeCssFlagClass((string) ($offer['salaryFlagClass'] ?? '')); ?>
                <li>
                    <?php if ($offerCompanyUrl !== ''): ?>
                        <a href="<?= esc($offerCompanyUrl) ?>" target="_blank" rel="noopener noreferrer"><?= esc($offerCompanyName !== '' ? $offerCompanyName : 'Empresa') ?></a>
                    <?php else: ?>
                        <?= esc($offerCompanyName !== '' ? $offerCompanyName : 'Empresa') ?>
                    <?php endif; ?>
                    |
                    <?php if ($offerFlagClass !== ''): ?>
                        <span class="xflagsSmall <?= esc($offerFlagClass) ?>"></span>
                    <?php endif; ?>
                    <?= esc($offerSalaryDisplay) ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php if ($jobOfferCount > count($offersToShow)): ?>
            <small style="color:#5a6a83;">Mostrando <?= count($offersToShow) ?> de <?= $jobOfferCount ?> ofertas.</small>
        <?php endif; ?>
        <?php
    } else {
        ?>
        <span style="color:var(--muted);">Sin ofertas detectadas en jobMarket para esta region.</span>
        <?php
    }

    return trim((string) ob_get_clean());
}

function renderOwnedCompaniesRegionHtml(array $companies, ?array $syncMeta = null): string
{
    if ($companies === []) {
        return '<span style="color:#5a6a83;">-</span>';
    }

    $npcCoverageByCompany = buildOwnedCompanyNpcCoverage($syncMeta);
    $regionNpcOwnership = summarizeRegionNpcOwnership($syncMeta);
    $regionHasNpcControl = (bool) ($regionNpcOwnership['hasControl'] ?? false);
    $rowCardStyle = $regionHasNpcControl
        ? 'padding:6px 8px;border:1px solid #b8e1bf;border-radius:8px;background:#eef9f0;'
        : 'padding:6px 8px;border:1px solid #dbe3ef;border-radius:8px;background:#fff;';

    ob_start();
    ?>
    <div style="display:flex;flex-direction:column;gap:4px;">
        <?php foreach ($companies as $company): ?>
            <?php if (!is_array($company)) { continue; } ?>
            <?php $companyName = trim((string) ($company['companyName'] ?? 'Empresa')); ?>
            <?php $companyUrl = trim((string) ($company['companyUrl'] ?? '')); ?>
            <?php $productTitle = trim((string) ($company['productTitle'] ?? '')); ?>
            <?php $productImageUrl = trim((string) ($company['productImageUrl'] ?? '')); ?>
            <?php $businessType = trim((string) ($company['businessType'] ?? '')); ?>
            <?php $companyKey = $companyUrl !== '' ? ('url:' . normalizeMatchText(toAbsoluteEsimUrl($companyUrl))) : ('name:' . normalizeMatchText($companyName)); ?>
            <?php $companyCoverage = is_array($npcCoverageByCompany[$companyKey] ?? null) ? $npcCoverageByCompany[$companyKey] : ['total' => 0, 'worked' => 0]; ?>
            <?php $companyNpcTotal = (int) ($companyCoverage['total'] ?? 0); ?>
            <?php $companyNpcWorked = (int) ($companyCoverage['worked'] ?? 0); ?>
            <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;<?= esc($rowCardStyle) ?>">
                <?php if ($productImageUrl !== ''): ?>
                    <img src="<?= esc($productImageUrl) ?>" alt="<?= esc($productTitle !== '' ? $productTitle : 'Producto') ?>" title="<?= esc($productTitle !== '' ? $productTitle : 'Producto') ?>" style="width:20px;height:20px;">
                <?php endif; ?>
                <?php if ($companyUrl !== ''): ?>
                    <a href="<?= esc($companyUrl) ?>" target="_blank" rel="noopener noreferrer"><?= esc($companyName !== '' ? $companyName : 'Empresa') ?></a>
                <?php else: ?>
                    <span><?= esc($companyName !== '' ? $companyName : 'Empresa') ?></span>
                <?php endif; ?>
                <?php if ($businessType !== ''): ?>
                    <small style="color:#5a6a83;"><?= esc($businessType) ?></small>
                <?php endif; ?>
                <small style="color:<?= $regionHasNpcControl ? '#1f6b31' : '#5a6a83' ?>;font-weight:600;">
                    <?= $regionHasNpcControl ? 'Control NPC: SI' : 'Control NPC: NO' ?>
                </small>
                <?php if ($companyNpcTotal > 0): ?>
                    <small style="color:#1f6b31;font-weight:600;">NPCs region en esta empresa: <?= $companyNpcTotal ?> | Ya trabajaron: <?= $companyNpcWorked ?></small>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php

    return trim((string) ob_get_clean());
}

function isSalaryInRange(?float $salaryValue, string $range): bool
{
    if ($salaryValue === null) {
        return false;
    }

    return match ($range) {
        '0-10' => $salaryValue >= 0 && $salaryValue <= 10,
        '11-20' => $salaryValue >= 11 && $salaryValue <= 20,
        '21-30' => $salaryValue >= 21 && $salaryValue <= 30,
        '31-40' => $salaryValue >= 31 && $salaryValue <= 40,
        '41-50' => $salaryValue >= 41 && $salaryValue <= 50,
        '50+' => $salaryValue > 50,
        default => true,
    };
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NPCs - Regiones</title>
    <link href="https://vara.e-sim.org/cdn/static/img/FlagsPackage/CSS/flagsStyle.css" rel="stylesheet" type="text/css">
    <style>
        :root {
            --bg: #f7f9fc;
            --card: #ffffff;
            --ink: #10213a;
            --muted: #5a6a83;
            --line: #dbe3ef;
            --accent: #0f6bbd;
            --accent-2: #0b5494;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: "Segoe UI", Tahoma, Arial, sans-serif;
            color: var(--ink);
            background: linear-gradient(135deg, #eef4fb 0%, #f8fbff 60%, #eff7f1 100%);
        }

        .wrap {
            max-width: 1400px;
            margin: 0 auto;
            padding: 16px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }

        h1 {
            margin: 0;
            font-size: 24px;
            line-height: 1.2;
        }

        .nav-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            color: #fff;
            background: var(--accent);
            border: 1px solid var(--accent-2);
            border-radius: 8px;
            padding: 8px 10px;
            font-weight: 600;
        }

        .nav-link:hover {
            background: var(--accent-2);
        }

        .card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 12px;
            margin-bottom: 12px;
        }

        .filters {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 10px;
            align-items: end;
        }

        label {
            display: block;
            font-size: 12px;
            color: var(--muted);
            margin-bottom: 4px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        select {
            width: 100%;
            min-height: 36px;
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 6px 8px;
            background: #fff;
            color: var(--ink);
        }

        .meta {
            margin-top: 8px;
            color: var(--muted);
            font-size: 13px;
        }

        .table-wrap {
            overflow: auto;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: #fff;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1120px;
        }

        th, td {
            text-align: left;
            padding: 9px 10px;
            border-bottom: 1px solid var(--line);
            vertical-align: top;
            font-size: 14px;
        }

        th {
            background: #f1f6fd;
            color: #274366;
            position: sticky;
            top: 0;
            z-index: 1;
        }

        tr:hover td {
            background: #f8fbff;
        }

        .yes {
            color: #05603a;
            font-weight: 700;
        }

        .no {
            color: #8a2f2f;
            font-weight: 700;
        }

        .warn {
            margin: 0;
            color: #8a2f2f;
            font-weight: 600;
        }

        .sync-alert {
            margin-bottom: 10px;
            border-radius: 10px;
            padding: 10px 12px;
            border: 1px solid #dbe3ef;
            font-size: 13px;
        }

        .sync-alert.ok {
            background: #eef9f0;
            border-color: #b8e1bf;
            color: #1f6b31;
        }

        .sync-alert.error {
            background: #fff1f1;
            border-color: #f0c0c0;
            color: #8a2f2f;
        }

        .resource-cell {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .resource-icon-wrap {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 3px;
            border-radius: 6px;
            background: #eef2f7;
        }

        .resource-icon-wrap.high {
            background: #9cff57;
        }

        .resource-icon-wrap.medium {
            background: #ffb347;
        }

        .resource-icon {
            width: 24px;
            height: 24px;
            display: block;
        }

        .resource-filter-group {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
            min-height: 36px;
        }

        .resource-choice {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: #fff;
            min-height: 42px;
            min-width: 42px;
            padding: 6px 8px;
            cursor: pointer;
        }

        .resource-choice input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .resource-choice.active {
            border-color: var(--accent);
            box-shadow: 0 0 0 2px rgba(15, 107, 189, 0.12);
        }

        .filter-hint {
            margin-top: 6px;
            color: var(--muted);
            font-size: 12px;
        }

        .resource-choice-text {
            font-size: 12px;
            font-weight: 600;
            color: var(--ink);
            line-height: 1;
            white-space: nowrap;
        }

        .sync-cell {
            min-width: 170px;
        }

        .sync-btn {
            min-height: 30px;
            border-radius: 7px;
            border: 1px solid var(--accent-2);
            background: var(--accent);
            color: #fff;
            padding: 0 10px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
        }

        .sync-btn.icon-only {
            min-height: 24px;
            width: 24px;
            min-width: 24px;
            padding: 0;
            border-radius: 6px;
            line-height: 1;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .owned-company-card {
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 8px;
            background: #fff;
            position: relative;
        }

        .owned-company-card.npc-control-yes {
            border-color: #b8e1bf;
            background: #eef9f0;
        }

        .owned-company-card.npc-control-no {
            border-color: #f0c0c0;
            background: #fff1f1;
        }

        .owned-company-card.salary-risk-high {
            border-color: #3b3b3b;
            background: linear-gradient(140deg, #111 0%, #1e1e1e 60%, #2a2a2a 100%);
            color: #e7e7e7;
        }

        .owned-company-card.salary-risk-high .owned-company-subtitle,
        .owned-company-card.salary-risk-high small,
        .owned-company-card.salary-risk-high .sync-inline-status,
        .owned-company-card.salary-risk-high .owned-company-npcs,
        .owned-company-card.salary-risk-high [data-company-npc-summary] {
            color: #cfd2d6 !important;
        }

        .owned-company-card.salary-risk-high a {
            color: #9fd0ff;
        }

        .owned-company-control-badge {
            font-size: 11px;
            font-weight: 700;
            padding: 2px 6px;
            border-radius: 999px;
            border: 1px solid var(--line);
            color: #5a6a83;
            background: #f5f8fd;
        }

        .owned-company-control-badge.ok {
            color: #1f6b31;
            border-color: #b8e1bf;
            background: #eef9f0;
        }

        .owned-company-header {
            display: flex;
            align-items: flex-start;
            gap: 6px;
            flex-wrap: wrap;
            padding-right: 34px;
        }

        .owned-company-title-wrap {
            display: flex;
            flex-direction: column;
            min-width: 0;
            gap: 2px;
        }

        .owned-company-subtitle {
            font-size: 11px;
            color: var(--muted);
            line-height: 1.2;
        }

        .owned-company-flag-corner {
            position: absolute;
            top: 6px;
            right: 6px;
            min-width: 22px;
            min-height: 18px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
            border: 1px solid #dbe3ef;
            background: #f8fbff;
            padding: 0 3px;
        }

        .owned-company-npcs {
            margin-top: 6px;
            font-size: 12px;
            color: #30445f;
            line-height: 1.35;
        }

        .owned-company-npcs ul {
            margin: 4px 0 0;
            padding-left: 16px;
        }

        .owned-company-npcs li {
            margin-bottom: 2px;
        }

        .npc-work-chip {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            border-radius: 999px;
            border: 1px solid #c8d7eb;
            padding: 2px 7px;
            font-size: 11px;
            font-weight: 700;
            line-height: 1.1;
            background: #eef4fb;
            color: #355277;
            vertical-align: middle;
        }

        .npc-work-chip.worked {
            background: #e6f1ff;
            border-color: #9ec1ea;
            color: #0b5ea8;
        }

        .npc-work-chip.pending {
            background: #eef1f5;
            border-color: #cfd6de;
            color: #4e5967;
        }

        .npc-work-chip.lost {
            background: #fff1f1;
            border-color: #f0c0c0;
            color: #8a2f2f;
        }

        .npc-work-chip .icon {
            font-size: 11px;
            line-height: 1;
        }

        .sync-meta {
            margin-top: 4px;
            font-size: 11px;
            color: var(--muted);
            line-height: 1.3;
        }

        .sync-details {
            margin-top: 6px;
            font-size: 11px;
            color: var(--ink);
        }

        .sync-details summary {
            cursor: pointer;
            color: var(--accent-2);
            font-weight: 600;
        }

        .sync-inline-status {
            margin-top: 4px;
            font-size: 11px;
            line-height: 1.35;
            font-weight: 600;
        }

        .sync-inline-status.ok {
            color: #1f6b31;
        }

        .sync-inline-status.error {
            color: #8a2f2f;
        }

        .sync-owner-status {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-weight: 600;
        }

        .sync-owner-status.ok {
            color: #1f6b31;
        }

        .sync-owner-status.warning {
            color: #8a2f2f;
        }

        .sync-npc-list {
            margin: 6px 0 0;
            padding-left: 16px;
            max-height: 180px;
            overflow: auto;
        }

        .sync-npc-list li {
            margin-bottom: 3px;
        }

        @media (max-width: 700px) {
            .wrap {
                padding: 12px;
            }

            h1 {
                font-size: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="header">
            <h1>Listado de Regiones (NPCs)</h1>
            <a class="nav-link" href="index.php">Volver al panel</a>
        </div>

        <div class="card">
            <?php if ($syncStatus !== ''): ?>
                <div class="sync-alert <?= $syncStatus === 'ok' ? 'ok' : 'error' ?>">
                    <strong><?= $syncStatus === 'ok' ? 'Sincronizacion OK' : 'Sincronizacion con error' ?></strong>
                    <?php if ($syncRegionName !== '' || $syncRegionId !== ''): ?>
                        | Region: <?= esc($syncRegionName !== '' ? $syncRegionName : ('#' . $syncRegionId)) ?>
                    <?php endif; ?>
                    <?php if ($syncStatus === 'ok'): ?>
                        | NPCs: <?= (int) $syncCount ?>
                    <?php endif; ?>
                    <?php if ($syncMessage !== ''): ?>
                        | <?= esc($syncMessage) ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($companySyncStatus !== ''): ?>
                <div class="sync-alert <?= $companySyncStatus === 'ok' ? 'ok' : 'error' ?>">
                    <strong><?= $companySyncStatus === 'ok' ? 'Empresas sincronizadas' : 'Error al sincronizar empresas' ?></strong>
                    <?php if ($companySyncStatus === 'ok'): ?>
                        | Empresas: <?= (int) $companySyncCount ?>
                    <?php endif; ?>
                    <?php if ($companySyncMessage !== ''): ?>
                        | <?= esc($companySyncMessage) ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <form method="post" class="filters" style="margin-bottom:10px;">
                <input type="hidden" name="action" value="sync-owned-companies">
                <input type="hidden" name="country" value="<?= esc($selectedCountry) ?>">
                <input type="hidden" name="owner" value="<?= esc($selectedOwner) ?>">
                <input type="hidden" name="salaryRange" value="<?= esc($selectedSalaryRange) ?>">
                <input type="hidden" name="ownedCompany" value="<?= esc($selectedOwnedCompany) ?>">
                <?php foreach ($selectedResourceTypes as $selectedType): ?>
                    <input type="hidden" name="resourceType[]" value="<?= esc((string) $selectedType) ?>">
                <?php endforeach; ?>
                <div style="grid-column: 1 / -1; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                    <button type="submit" class="sync-btn">Sincronizar empresas</button>
                    <span class="filter-hint" style="margin-top:0;">
                        Fuente: <a href="https://vara.e-sim.org/business.html?businessType=COMPANIES" target="_blank" rel="noopener noreferrer">business.html?businessType=COMPANIES</a>
                        <?php if (!empty($ownedCompaniesCache['syncedAtDisplay'])): ?>
                            | Ultima sync: <?= esc((string) $ownedCompaniesCache['syncedAtDisplay']) ?>
                        <?php endif; ?>
                        | Empresas guardadas: <?= count($ownedCompanies) ?>
                    </span>
                </div>
                <?php if ($ownedCompanies !== []): ?>
                    <div style="grid-column: 1 / -1;">
                        <details class="sync-details">
                            <summary>Ver mis empresas sincronizadas</summary>
                            <div style="margin-top:8px; display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap:8px;">
                                <?php foreach ($ownedCompanies as $company): ?>
                                    <?php if (!is_array($company)) { continue; } ?>
                                    <?php $companyRegionId = trim((string) ($company['regionId'] ?? '')); ?>
                                    <?php $companySyncMeta = $companyRegionId !== '' && is_array($npcCache['regions'][$companyRegionId] ?? null) ? $npcCache['regions'][$companyRegionId] : null; ?>
                                    <?php $companyRegionCatalog = $companyRegionId !== '' && is_array($regionCatalogById[$companyRegionId] ?? null) ? $regionCatalogById[$companyRegionId] : null; ?>
                                    <?php $companyRegionOwnership = summarizeRegionNpcOwnership($companySyncMeta); ?>
                                    <?php $companyRegionHasControl = (bool) ($companyRegionOwnership['hasControl'] ?? false); ?>
                                    <?php $companyCoverageByKey = buildOwnedCompanyNpcCoverage($companySyncMeta); ?>
                                    <?php $companyUrlForCoverage = toAbsoluteEsimUrl(trim((string) ($company['companyUrl'] ?? ''))); ?>
                                    <?php $companyNameForCoverage = trim((string) ($company['companyName'] ?? '')); ?>
                                    <?php $companyCoverageKey = $companyUrlForCoverage !== '' ? ('url:' . normalizeMatchText($companyUrlForCoverage)) : ('name:' . normalizeMatchText($companyNameForCoverage)); ?>
                                    <?php $companyCoverage = is_array($companyCoverageByKey[$companyCoverageKey] ?? null) ? $companyCoverageByKey[$companyCoverageKey] : ['total' => 0, 'worked' => 0]; ?>
                                    <?php $companyNpcCoverageTotal = (int) ($companyCoverage['total'] ?? 0); ?>
                                    <?php $companyNpcCoverageWorked = (int) ($companyCoverage['worked'] ?? 0); ?>
                                    <?php $companyRegionNpcs = is_array($companySyncMeta['npcs'] ?? null) ? array_slice((array) $companySyncMeta['npcs'], 0, 3) : []; ?>
                                    <?php $companyRegionMaxSalary = extractRegionMaxSalaryValue($companySyncMeta); ?>
                                    <?php $companySalaryRiskHigh = $companyRegionMaxSalary !== null && $companyRegionMaxSalary > 60; ?>
                                    <?php $syncRegionNameForCard = trim((string) ($company['regionName'] ?? '')); ?>
                                    <?php if ($syncRegionNameForCard === '' && is_array($companyRegionCatalog)): ?>
                                        <?php $syncRegionNameForCard = trim((string) ($companyRegionCatalog['name'] ?? '')); ?>
                                    <?php endif; ?>
                                    <?php $syncRegionUrlForCard = trim((string) ($company['regionUrl'] ?? '')); ?>
                                    <?php if ($syncRegionUrlForCard === '' && is_array($companyRegionCatalog)): ?>
                                        <?php $syncRegionUrlForCard = trim((string) ($companyRegionCatalog['url'] ?? '')); ?>
                                    <?php endif; ?>
                                    <?php if ($syncRegionUrlForCard === '' && $companyRegionId !== ''): ?>
                                        <?php $syncRegionUrlForCard = 'https://vara.e-sim.org/region.html?id=' . rawurlencode($companyRegionId); ?>
                                    <?php endif; ?>
                                    <?php $syncRegionOwnerForCard = is_array($companyRegionCatalog) ? trim((string) ($companyRegionCatalog['currentOwner'] ?? '')) : ''; ?>
                                    <?php $controllerCountryForCard = is_array($companySyncMeta) ? trim((string) ($companySyncMeta['ownerAtSync'] ?? '')) : $syncRegionOwnerForCard; ?>
                                    <?php $controllerFlagClassForCard = is_array($companySyncMeta)
                                        ? sanitizeCssFlagClass((string) ($companySyncMeta['ownerAtSyncFlagClass'] ?? ''))
                                        : '';
                                    ?>
                                    <?php if ($controllerFlagClassForCard === '' && $controllerCountryForCard !== ''): ?>
                                        <?php $controllerFlagClassForCard = sanitizeCssFlagClass(flagClassFromCountryName($controllerCountryForCard)); ?>
                                    <?php endif; ?>
                                    <div class="owned-company-card <?= $companyRegionHasControl ? 'npc-control-yes' : 'npc-control-no' ?> <?= $companySalaryRiskHigh ? 'salary-risk-high' : '' ?>" data-company-region-id="<?= esc($companyRegionId) ?>">
                                        <?php if ($controllerFlagClassForCard !== ''): ?>
                                            <span class="owned-company-flag-corner" title="Controla: <?= esc($controllerCountryForCard !== '' ? $controllerCountryForCard : 'Desconocido') ?>" data-company-owner-flag>
                                                <span class="xflagsSmall <?= esc($controllerFlagClassForCard) ?>"></span>
                                            </span>
                                        <?php endif; ?>
                                        <div class="owned-company-header">
                                            <?php $productImageUrl = trim((string) ($company['productImageUrl'] ?? '')); ?>
                                            <?php if ($productImageUrl !== ''): ?>
                                                <img src="<?= esc($productImageUrl) ?>" alt="Producto" style="width:20px;height:20px;">
                                            <?php endif; ?>
                                            <?php $companyUrl = trim((string) ($company['companyUrl'] ?? '')); ?>
                                            <?php $companyName = trim((string) ($company['companyName'] ?? 'Empresa')); ?>
                                            <div class="owned-company-title-wrap">
                                                <?php if ($companyUrl !== ''): ?>
                                                    <a href="<?= esc($companyUrl) ?>" target="_blank" rel="noopener noreferrer"><strong><?= esc($companyName) ?></strong></a>
                                                <?php else: ?>
                                                    <strong><?= esc($companyName) ?></strong>
                                                <?php endif; ?>
                                                <div class="owned-company-subtitle"><?= esc($syncRegionNameForCard !== '' ? $syncRegionNameForCard : 'Region sin nombre') ?></div>
                                            </div>
                                            <?php $businessType = trim((string) ($company['businessType'] ?? '')); ?>
                                            <?php if ($businessType !== ''): ?>
                                                <small style="color:var(--muted);"><?= esc($businessType) ?></small>
                                            <?php endif; ?>
                                            <span class="owned-company-control-badge <?= $companyRegionHasControl ? 'ok' : '' ?>" data-company-control-badge>
                                                <?= $companyRegionHasControl ? 'Control NPC SI' : 'Control NPC NO' ?>
                                            </span>
                                            <?php if ($companyRegionId !== ''): ?>
                                                <form method="post" class="sync-region-form" style="margin:0;" data-region-id="<?= esc($companyRegionId) ?>" data-company-card-sync="1">
                                                    <input type="hidden" name="action" value="sync-region-npcs">
                                                    <input type="hidden" name="async" value="1">
                                                    <input type="hidden" name="regionId" value="<?= esc($companyRegionId) ?>">
                                                    <input type="hidden" name="regionName" value="<?= esc($syncRegionNameForCard) ?>">
                                                    <input type="hidden" name="regionUrl" value="<?= esc($syncRegionUrlForCard) ?>">
                                                    <input type="hidden" name="currentOwnerSnapshot" value="<?= esc($syncRegionOwnerForCard) ?>">
                                                    <button type="submit" class="sync-btn icon-only" title="Sincronizar region NPC" aria-label="Sincronizar region NPC" data-loading-label="...">&#8635;</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                        <div style="margin-top:4px; font-size:12px; color:var(--muted);">
                                            <?php if ((string) ($company['regionId'] ?? '') !== ''): ?>
                                                Region ID: #<?= esc((string) $company['regionId']) ?>
                                            <?php endif; ?>
                                            <?php if ((string) ($company['countryName'] ?? '') !== ''): ?>
                                                | Pais: <?= esc((string) $company['countryName']) ?>
                                            <?php endif; ?>
                                            | <span data-company-npc-summary>En empresas propias: <?= (int) ($companyRegionOwnership['ownedCount'] ?? 0) ?>/<?= (int) ($companyRegionOwnership['totalCount'] ?? 0) ?> NPCs</span>
                                            <?php if ($companyNpcCoverageTotal > 0): ?>
                                                | En esta empresa: <?= $companyNpcCoverageTotal ?> (Ya trabajaron: <?= $companyNpcCoverageWorked ?>)
                                            <?php endif; ?>
                                        </div>
                                        <div class="owned-company-npcs" data-company-npcs-list>
                                            <?= renderCompanyRegionNpcsHtml($companySyncMeta) ?>
                                        </div>
                                        <div class="owned-company-npcs" data-company-job-offers-list>
                                            <?= renderCompanyRegionJobOffersHtml($companySyncMeta) ?>
                                        </div>
                                        <div class="sync-inline-status" data-company-sync-status></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </details>
                    </div>
                <?php endif; ?>
            </form>

            <form method="post" class="filters" style="margin-bottom:10px;">
                <input type="hidden" name="action" value="save-sync-cookie">
                <input type="hidden" name="country" value="<?= esc($selectedCountry) ?>">
                <input type="hidden" name="owner" value="<?= esc($selectedOwner) ?>">
                <input type="hidden" name="salaryRange" value="<?= esc($selectedSalaryRange) ?>">
                <input type="hidden" name="ownedCompany" value="<?= esc($selectedOwnedCompany) ?>">
                <?php foreach ($selectedResourceTypes as $selectedType): ?>
                    <input type="hidden" name="resourceType[]" value="<?= esc((string) $selectedType) ?>">
                <?php endforeach; ?>
            </form>

            <form method="get" class="filters" id="filters-form">
                <div>
                    <label for="country">Pais</label>
                    <select id="country" name="country">
                        <option value="">Todos</option>
                        <?php foreach ($countryList as $country): ?>
                            <option value="<?= esc((string) $country) ?>" <?= $selectedCountry === (string) $country ? 'selected' : '' ?>><?= esc((string) $country) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Recurso</label>
                    <div class="resource-filter-group">
                        <?php $noneSelected = in_array('none', $selectedResourceTypes, true); ?>
                        <label class="resource-choice <?= $noneSelected ? 'active' : '' ?>" title="Sin recursos">
                            <input type="checkbox" name="resourceType[]" value="none" <?= $noneSelected ? 'checked' : '' ?>>
                            <span class="resource-choice-text">Sin recursos</span>
                        </label>
                        <?php foreach ($resourceTypeList as $resourceType): ?>
                            <?php $iconUrl = (string) (($resourceOptions[$resourceType]['iconUrl'] ?? '') ?: ''); ?>
                            <?php $resourceTypeSelected = in_array($resourceType, $selectedResourceTypes, true); ?>
                            <label class="resource-choice <?= $resourceTypeSelected ? 'active' : '' ?>" title="<?= esc(ucfirst((string) $resourceType)) ?>">
                                <input type="checkbox" name="resourceType[]" value="<?= esc((string) $resourceType) ?>" <?= $resourceTypeSelected ? 'checked' : '' ?>>
                                <?php if ($iconUrl !== ''): ?>
                                    <img class="resource-icon" src="<?= esc($iconUrl) ?>" alt="<?= esc((string) $resourceType) ?>" loading="lazy">
                                <?php else: ?>
                                    <span class="resource-choice-text"><?= esc((string) ucfirst((string) $resourceType)) ?></span>
                                <?php endif; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div>
                    <label for="owner">Ocupante (currentOwner)</label>
                    <select id="owner" name="owner">
                        <option value="">Todos</option>
                        <?php foreach ($ownerList as $owner): ?>
                            <option value="<?= esc((string) $owner) ?>" <?= $selectedOwner === $owner ? 'selected' : '' ?>><?= esc((string) $owner) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="salaryRange">Rango sueldo base NPC</label>
                    <select id="salaryRange" name="salaryRange">
                        <option value="">Todos</option>
                        <option value="0-10" <?= $selectedSalaryRange === '0-10' ? 'selected' : '' ?>>0 a 10</option>
                        <option value="11-20" <?= $selectedSalaryRange === '11-20' ? 'selected' : '' ?>>11 a 20</option>
                        <option value="21-30" <?= $selectedSalaryRange === '21-30' ? 'selected' : '' ?>>21 a 30</option>
                        <option value="31-40" <?= $selectedSalaryRange === '31-40' ? 'selected' : '' ?>>31 a 40</option>
                        <option value="41-50" <?= $selectedSalaryRange === '41-50' ? 'selected' : '' ?>>41 a 50</option>
                        <option value="50+" <?= $selectedSalaryRange === '50+' ? 'selected' : '' ?>>50+</option>
                    </select>
                </div>

                <div>
                    <label for="ownedCompany">Regiones con empresa mia</label>
                    <select id="ownedCompany" name="ownedCompany">
                        <option value="" <?= $selectedOwnedCompany === '' ? 'selected' : '' ?>>Todas</option>
                        <option value="yes" <?= $selectedOwnedCompany === 'yes' ? 'selected' : '' ?>>Solo con empresa propia</option>
                    </select>
                </div>

            </form>
        </div>

        <?php if ($loadError !== ''): ?>
            <div class="card">
                <p class="warn"><?= esc($loadError) ?></p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Pais</th>
                            <th>Region ID</th>
                            <th>Region</th>
                            <th>Ocupante</th>
                            <th>Resource</th>
                            <th>Mis empresas</th>
                            <th>Sueldo base NPC (max)</th>
                            <th>Sync NPCs</th>
                            <th>URL</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($visibleRows === 0): ?>
                            <tr>
                                <td colspan="9">Sin resultados para el filtro actual.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($filteredRows as $row): ?>
                                <?php $rowSyncMeta = is_array($npcCache['regions'][(string) ($row['id'] ?? '')] ?? null) ? $npcCache['regions'][(string) ($row['id'] ?? '')] : null; ?>
                                <?php $rowMaxSalaryInfo = extractRegionMaxSalaryInfo($rowSyncMeta); ?>
                                <?php $rowMaxSalaryValue = is_array($rowMaxSalaryInfo) && isset($rowMaxSalaryInfo['value']) && is_numeric($rowMaxSalaryInfo['value']) ? (float) $rowMaxSalaryInfo['value'] : null; ?>
                                <?php $rowOwnedCompanies = is_array($ownedCompaniesByRegion[(string) ($row['id'] ?? '')] ?? null) ? (array) $ownedCompaniesByRegion[(string) ($row['id'] ?? '')] : []; ?>
                                <?php $displayCountry = trim((string) ($row['countryName'] ?? '')); ?>
                                <?php $displayOwner = trim((string) ($row['currentOwner'] ?? '')); ?>
                                <?php $displayOwnerFlagClass = ''; ?>
                                <?php if (is_array($rowSyncMeta)): ?>
                                    <?php $syncedOwner = trim((string) ($rowSyncMeta['ownerAtSync'] ?? '')); ?>
                                    <?php if ($syncedOwner !== ''): ?>
                                        <?php $displayOwner = $syncedOwner; ?>
                                    <?php endif; ?>
                                    <?php $displayOwnerFlagClass = sanitizeCssFlagClass((string) ($rowSyncMeta['ownerAtSyncFlagClass'] ?? '')); ?>
                                <?php endif; ?>
                                <tr data-region-id="<?= esc((string) ($row['id'] ?? '')) ?>">
                                    <td data-country-cell><?= renderCountryWithFlagHtml($displayCountry) ?></td>
                                    <td><?= esc((string) ($row['id'] ?? '')) ?></td>
                                    <td><?= esc((string) ($row['name'] ?? '')) ?></td>
                                    <td data-occupant-cell><?= renderCountryWithFlagHtml($displayOwner, $displayOwnerFlagClass) ?></td>
                                    <td>
                                        <?php $resourceMeta = parseResourceMeta((string) ($row['resource'] ?? '')); ?>
                                        <?php if (($resourceMeta['label'] ?? '') !== ''): ?>
                                            <span class="resource-cell">
                                                <?php if (($resourceMeta['iconUrl'] ?? '') !== ''): ?>
                                                    <span class="resource-icon-wrap <?= esc((string) ($resourceMeta['quality'] ?? '')) ?>">
                                                        <img
                                                            class="resource-icon"
                                                            src="<?= esc((string) $resourceMeta['iconUrl']) ?>"
                                                            alt="<?= esc((string) $resourceMeta['label']) ?>"
                                                            loading="lazy"
                                                        >
                                                    </span>
                                                <?php endif; ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= renderOwnedCompaniesRegionHtml($rowOwnedCompanies, $rowSyncMeta) ?>
                                    </td>
                                    <td data-salary-base-cell>
                                        <?= renderRegionBaseSalaryCellHtml($rowMaxSalaryInfo) ?>
                                    </td>
                                    <td class="sync-cell">
                                        <form method="post" class="sync-region-form" data-region-id="<?= esc((string) ($row['id'] ?? '')) ?>">
                                            <input type="hidden" name="action" value="sync-region-npcs">
                                            <input type="hidden" name="async" value="1">
                                            <input type="hidden" name="regionId" value="<?= esc((string) ($row['id'] ?? '')) ?>">
                                            <input type="hidden" name="regionName" value="<?= esc((string) ($row['name'] ?? '')) ?>">
                                            <input type="hidden" name="regionUrl" value="<?= esc((string) ($row['url'] ?? '')) ?>">
                                            <input type="hidden" name="currentOwnerSnapshot" value="<?= esc((string) ($row['currentOwner'] ?? '')) ?>">
                                            <input type="hidden" name="country" value="<?= esc($selectedCountry) ?>">
                                            <input type="hidden" name="owner" value="<?= esc($selectedOwner) ?>">
                                            <input type="hidden" name="salaryRange" value="<?= esc($selectedSalaryRange) ?>">
                                            <input type="hidden" name="ownedCompany" value="<?= esc($selectedOwnedCompany) ?>">
                                            <?php foreach ($selectedResourceTypes as $selectedType): ?>
                                                <input type="hidden" name="resourceType[]" value="<?= esc((string) $selectedType) ?>">
                                            <?php endforeach; ?>
                                            <button type="submit" class="sync-btn" data-default-label="Sincronizar region">Sincronizar region</button>
                                        </form>
                                        <div class="sync-inline-status" data-sync-status></div>
                                        <div class="sync-result" data-sync-result>
                                            <?= renderSyncMetaHtml($rowSyncMeta) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php $url = trim((string) ($row['url'] ?? '')); ?>
                                        <?php if ($url !== ''): ?>
                                            <a href="<?= esc($url) ?>" target="_blank" rel="noopener noreferrer">Abrir</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <script>
        (function () {
            var form = document.getElementById('filters-form');
            if (!form) {
                return;
            }

            form.addEventListener('change', function () {
                form.submit();
            });
        })();

        (function () {
            var syncForms = document.querySelectorAll('.sync-region-form');
            if (!syncForms.length) {
                return;
            }

            syncForms.forEach(function (syncForm) {
                syncForm.addEventListener('submit', function (event) {
                    event.preventDefault();

                    var submitBtn = syncForm.querySelector('.sync-btn');
                    var statusBox = syncForm.parentElement ? syncForm.parentElement.querySelector('[data-sync-status]') : null;
                    var resultBox = syncForm.parentElement ? syncForm.parentElement.querySelector('[data-sync-result]') : null;
                    var defaultLabel = submitBtn
                        ? (submitBtn.getAttribute('data-default-label') || submitBtn.textContent || 'Sincronizar region')
                        : 'Sincronizar region';
                    var loadingLabel = submitBtn ? (submitBtn.getAttribute('data-loading-label') || 'Sincronizando...') : 'Sincronizando...';

                    if (submitBtn) {
                        submitBtn.disabled = true;
                        submitBtn.textContent = loadingLabel;
                    }
                    if (statusBox) {
                        statusBox.className = 'sync-inline-status';
                        statusBox.textContent = '';
                    }

                    var formData = new FormData(syncForm);
                    formData.set('async', '1');

                    fetch('npcs.php', {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                        .then(function (response) {
                            return response.json();
                        })
                        .then(function (payload) {
                            var ok = !!(payload && payload.ok);
                            var message = payload && payload.syncMessage ? String(payload.syncMessage) : (ok ? 'Sincronizacion OK.' : 'Sincronizacion con error.');
                            var syncedRegionId = payload && payload.syncRegionId ? String(payload.syncRegionId) : '';

                            if (resultBox && payload && typeof payload.syncHtml === 'string') {
                                resultBox.innerHTML = payload.syncHtml;
                                var details = resultBox.querySelector('details.sync-details');
                                if (details) {
                                    details.open = true;
                                }
                            }

                            if (statusBox) {
                                statusBox.className = 'sync-inline-status ' + (ok ? 'ok' : 'error');
                                statusBox.textContent = message;
                            }

                            if (syncedRegionId !== '') {
                                var regionRowNode = document.querySelector('tr[data-region-id="' + syncedRegionId + '"]');
                                if (regionRowNode) {
                                    if (payload && typeof payload.ownerCellHtml === 'string' && payload.ownerCellHtml !== '') {
                                        var occupantCell = regionRowNode.querySelector('[data-occupant-cell]');
                                        if (occupantCell) {
                                            occupantCell.innerHTML = payload.ownerCellHtml;
                                        }
                                    }

                                    if (payload && typeof payload.salaryBaseCellHtml === 'string' && payload.salaryBaseCellHtml !== '') {
                                        var salaryBaseCell = regionRowNode.querySelector('[data-salary-base-cell]');
                                        if (salaryBaseCell) {
                                            salaryBaseCell.innerHTML = payload.salaryBaseCellHtml;
                                        }
                                    }

                                    var rowStatusBox = regionRowNode.querySelector('[data-sync-status]');
                                    if (rowStatusBox) {
                                        rowStatusBox.className = 'sync-inline-status ' + (ok ? 'ok' : 'error');
                                        rowStatusBox.textContent = message;
                                    }

                                    if (payload && typeof payload.syncHtml === 'string') {
                                        var rowResultBox = regionRowNode.querySelector('[data-sync-result]');
                                        if (rowResultBox) {
                                            rowResultBox.innerHTML = payload.syncHtml;
                                            var rowDetails = rowResultBox.querySelector('details.sync-details');
                                            if (rowDetails) {
                                                rowDetails.open = true;
                                            }
                                        }
                                    }
                                }
                            }

                            if (syncedRegionId !== '') {
                                var companyCards = document.querySelectorAll('[data-company-region-id="' + syncedRegionId + '"]');
                                companyCards.forEach(function (companyCard) {
                                    var hasControl = !!(payload && payload.regionNpcControl);
                                    var maxSalaryValue = payload && typeof payload.regionMaxSalaryValue === 'number' ? payload.regionMaxSalaryValue : null;
                                    var highSalaryRisk = maxSalaryValue !== null && maxSalaryValue > 60;
                                    companyCard.classList.toggle('npc-control-yes', hasControl);
                                    companyCard.classList.toggle('npc-control-no', !hasControl);
                                    companyCard.classList.toggle('salary-risk-high', highSalaryRisk);

                                    var badge = companyCard.querySelector('[data-company-control-badge]');
                                    if (badge) {
                                        badge.classList.toggle('ok', hasControl);
                                        badge.textContent = hasControl ? 'Control NPC SI' : 'Control NPC NO';
                                    }

                                    var npcSummary = companyCard.querySelector('[data-company-npc-summary]');
                                    if (npcSummary) {
                                        var ownedCount = payload && typeof payload.regionNpcOwnedCount !== 'undefined' ? Number(payload.regionNpcOwnedCount) : 0;
                                        var totalCount = payload && typeof payload.regionNpcTotalCount !== 'undefined' ? Number(payload.regionNpcTotalCount) : 0;
                                        npcSummary.textContent = 'En empresas propias: ' + ownedCount + '/' + totalCount + ' NPCs';
                                    }

                                    var ownerFlag = companyCard.querySelector('[data-company-owner-flag]');
                                    if (ownerFlag && payload && typeof payload.ownerAtSyncFlagClass === 'string') {
                                        var safeFlagClass = payload.ownerAtSyncFlagClass.match(/^xflagsSmall-[A-Za-z0-9-]+$/) ? payload.ownerAtSyncFlagClass : '';
                                        var ownerName = payload && typeof payload.ownerAtSync === 'string' && payload.ownerAtSync !== '' ? payload.ownerAtSync : 'Desconocido';
                                        if (safeFlagClass !== '') {
                                            ownerFlag.innerHTML = '<span class="xflagsSmall ' + safeFlagClass + '"></span>';
                                        }
                                        ownerFlag.setAttribute('title', 'Controla: ' + ownerName);
                                    }

                                    var companyStatus = companyCard.querySelector('[data-company-sync-status]');
                                    if (companyStatus) {
                                        companyStatus.className = 'sync-inline-status ' + (ok ? 'ok' : 'error');
                                        companyStatus.textContent = message;
                                    }

                                    if (payload && typeof payload.companyNpcListHtml === 'string') {
                                        var npcListBox = companyCard.querySelector('[data-company-npcs-list]');
                                        if (npcListBox) {
                                            npcListBox.innerHTML = payload.companyNpcListHtml;
                                        }
                                    }

                                    if (payload && typeof payload.companyJobOffersHtml === 'string') {
                                        var offersListBox = companyCard.querySelector('[data-company-job-offers-list]');
                                        if (offersListBox) {
                                            offersListBox.innerHTML = payload.companyJobOffersHtml;
                                        }
                                    }
                                });
                            }
                        })
                        .catch(function () {
                            if (statusBox) {
                                statusBox.className = 'sync-inline-status error';
                                statusBox.textContent = 'Error de red al sincronizar la region.';
                            }
                        })
                        .finally(function () {
                            if (submitBtn) {
                                submitBtn.disabled = false;
                                submitBtn.textContent = defaultLabel;
                            }
                        });
                });
            });
        })();
    </script>
</body>
</html>
