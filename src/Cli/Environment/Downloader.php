<?php

declare(strict_types=1);

namespace Golem\Cli\Environment;

use Golem\Cli\UserError;

/**
 * HTTP downloads using PHP streams only, so the CLI has no extension requirements.
 */
final class Downloader
{
    private const USER_AGENT = 'golem-cli (+https://github.com/achedon12/golem)';

    /**
     * Downloads to a temporary file next to the destination, then moves it in place,
     * so an interrupted download never leaves a broken file in the cache.
     */
    public function download(string $url, string $destination): void
    {
        $directory = dirname($destination);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new UserError("Cannot create the cache folder $directory");
        }

        $source = @fopen($url, 'rb', false, $this->context());
        if ($source === false) {
            throw new UserError("Download failed: $url" . $this->lastError());
        }
        $partial = $destination . '.part';
        $target = fopen($partial, 'wb');
        if ($target === false) {
            fclose($source);
            throw new UserError("Cannot write $partial");
        }

        $copied = stream_copy_to_stream($source, $target);
        fclose($source);
        fclose($target);
        if ($copied === false || $copied === 0) {
            @unlink($partial);
            throw new UserError("Download failed: $url returned nothing");
        }
        rename($partial, $destination);
    }

    /**
     * Follows a GitHub "latest" link without downloading it, to learn which version it points to.
     */
    public function resolveRedirect(string $url): string
    {
        $context = $this->context(['follow_location' => 0, 'method' => 'HEAD']);
        $headers = @get_headers($url, true, $context);
        if ($headers === false) {
            throw new UserError("Cannot reach $url" . $this->lastError());
        }
        $location = $headers['Location'] ?? $headers['location'] ?? null;
        if (is_array($location)) {
            $location = $location[0] ?? null;
        }
        if (!is_string($location)) {
            throw new UserError("Expected $url to redirect somewhere");
        }

        return $location;
    }

    /**
     * @param array<string, mixed> $http
     * @return resource
     */
    private function context(array $http = [])
    {
        return stream_context_create(['http' => $http + [
            'user_agent' => self::USER_AGENT,
            'follow_location' => 1,
            'max_redirects' => 10,
            'timeout' => 60,
            'ignore_errors' => false,
        ]]);
    }

    private function lastError(): string
    {
        $error = error_get_last();

        return $error !== null ? ' (' . $error['message'] . ')' : '';
    }
}
