<?php

declare(strict_types=1);

require __DIR__ . '/../src/Persian.php';
require __DIR__ . '/../src/LawArticleRequest.php';

function lawRequestAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$cases = [
    ['م ۱ ق ا ا', ['law' => 'value-added-tax', 'number' => 1]],
    ['تست ماده 10 ق ا ا تست', ['law' => 'value-added-tax', 'number' => 10]],
    ['م ٤٢ ق ا ا', ['law' => 'value-added-tax', 'number' => 42]],
    ['مادهٔ ۲۰ ت ا', ['law' => 'social-security', 'number' => 20]],
    ['م ٤٢ ت ا', ['law' => 'social-security', 'number' => 42]],
    ['ماده 20 ق ک', ['law' => 'labor-code', 'number' => 20]],
    ['م ۳ ق ک', ['law' => 'labor-code', 'number' => 3]],
    ['استاندارد حسابداری ۱', ['law' => 'accounting-standard', 'number' => 1]],
    ['استاندارد حسابرسی 200', ['law' => 'auditing-standard', 'number' => 200]],
    ['م 1 ق م م', null],
    ['یک متن معمولی دربارهٔ ارزش افزوده', null],
    ['م 1 ق ا ا و م 2 ت ا', ['ambiguous' => true]],
    ['استاندارد حسابداری 1 و استاندارد حسابرسی 200', ['ambiguous' => true]],
    ['م 0 ق ا ا', ['ambiguous' => true]],
    ['م 1001 ت ا', ['ambiguous' => true]],
];

foreach ($cases as [$input, $expected]) {
    lawRequestAssert(
        LawArticleRequest::parse($input) === $expected,
        'request parser mismatch: ' . $input
    );
}

lawRequestAssert(!LawArticleRequest::hasMultipleRecognizedRequests('م 1 ق م م'), 'single direct-tax request was marked ambiguous');
lawRequestAssert(LawArticleRequest::hasMultipleRecognizedRequests('م 1 ق م م و م 2 ق م م'), 'two direct-tax requests were not marked ambiguous');
lawRequestAssert(LawArticleRequest::hasMultipleRecognizedRequests('م 1 ق م م و م 2 ق ا ا'), 'mixed direct-tax and VAT requests were not marked ambiguous');
lawRequestAssert(LawArticleRequest::hasMultipleRecognizedRequests('م 1 ق ا ا و م 2 ت ا'), 'two non-direct-tax requests were not marked ambiguous');

lawRequestAssert(LawArticleRequest::isReady('value-added-tax'), 'VAT archive should be enabled');
lawRequestAssert(LawArticleRequest::isReady('social-security'), 'Social Security archive should be enabled');
foreach (['labor-code', 'accounting-standard', 'auditing-standard'] as $notReady) {
    lawRequestAssert(!LawArticleRequest::isReady($notReady), 'unverified archive was marked ready: ' . $notReady);
}

fwrite(STDOUT, "OK: legal request patterns, digit forms, ambiguity, and readiness gates.\n");
