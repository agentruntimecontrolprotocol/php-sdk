<?php

declare(strict_types=1);

/*
 * extensions — SDR domain via custom `arcpx.sdr.*.v1` extension messages.
 *
 * Tune to 145.500 MHz (2 m FM calling), capture 5 s of IQ at 2.048 MS/s,
 * NBFM-demodulate to 48 kHz PCM. Exercises §21 naming, capability
 * advertisement, and unknown-message handling.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Arcp\Client\ARCPClient;
use Arcp\Errors\InvalidRequestException;

const EXT_TUNE = 'arcpx.sdr.tune.v1';
const EXT_GAIN = 'arcpx.sdr.gain.v1';
const EXT_CAPTURE = 'arcpx.sdr.capture.v1';
const EXT_DEMODULATE = 'arcpx.sdr.demodulate.v1';
const ALL_EXTENSIONS = [EXT_TUNE, EXT_GAIN, EXT_CAPTURE, EXT_DEMODULATE];

function main(): void
{
    // capabilities.extensions = ALL_EXTENSIONS on the open call.
    /** @var ARCPClient $client */
    $client = elided();

    // If the runtime didn't advertise our required extension set,
    // refuse the session — extension namespaces ride as a
    // vendor-namespaced capability key (§6.2 `extra` passthrough).
    $caps = $client->session->capabilities;
    $advertisedRaw = $caps !== null ? ($caps->extra['arcpx.extensions.v1'] ?? []) : [];
    $advertised = array_values(array_filter(
        is_array($advertisedRaw) ? $advertisedRaw : [],
        static fn (mixed $ext): bool => is_string($ext),
    ));
    $missing = array_diff(ALL_EXTENSIONS, $advertised);
    if ($missing !== []) {
        throw new InvalidRequestException('runtime missing SDR extensions: ' . implode(',', $missing));
    }

    $handle = bin2hex(random_bytes(4));

    // Custom-typed envelopes: `invokeTool` is the established command
    // shape, but for non-tool extensions you mint an Envelope around
    // a registered ExtensionNamespace payload. The wire type is
    // `arcpx.sdr.tune.v1` straight through.
    $client->invokeTool(EXT_TUNE, [
        'center_freq_hz' => 145_500_000.0,
        'sample_rate_hz' => 2_048_000.0,
        'ppm_correction' => 1,
    ]);
    $client->invokeTool(EXT_GAIN, [
        'stages' => [['name' => 'TUNER', 'value_db' => 28.0]],
    ]);

    // Capture returns an artifact.ref pointing at the IQ buffer.
    // The buffer never travels inline — demodulate references it.
    $cap = $client->invokeTool(EXT_CAPTURE, [
        'seconds' => 5.0,
        'capture_handle' => $handle,
        'decimate' => 1,
    ]);
    $iqArtifact = artifactIdOf($cap->result);
    printf("captured IQ -> %s\n", $iqArtifact);

    $audio = $client->invokeTool(EXT_DEMODULATE, [
        'iq_artifact_id' => $iqArtifact,
        'mode' => 'NBFM',
        'audio_rate_hz' => 48_000,
    ]);
    printf("demod  PCM -> %s\n", artifactIdOf($audio->result));

    // §21.3 demonstration: unadvertised extension marked optional.
    // Runtime SHOULD ack (silent drop) rather than nack.
    $optional = $client->invokeTool('arcpx.sdr.experimental_doppler.v1', [
        'velocity_mps' => 7.4,
        '__optional' => true,
    ]);
    printf("optional unknown -> %s\n", (string) $optional::typeName());

    $client->close();
}

function elided(): ARCPClient
{
    throw new \RuntimeException('not implemented');
}

function artifactIdOf(mixed $value): string
{
    if (is_array($value) && isset($value['artifact_id']) && is_string($value['artifact_id'])) {
        return $value['artifact_id'];
    }
    return '';
}

main();
