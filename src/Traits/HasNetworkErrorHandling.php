<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Traits;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Exception\TransferException;
use KenDeNigerian\PayZephyr\Constants\HttpStatusCodes;
use Psr\Http\Message\ResponseInterface;

/**
 * Trait providing network error handling functionality.
 */
trait HasNetworkErrorHandling
{
    /**
     * Handle network errors gracefully with better logging and context.
     *
     * This method distinguishes between different types of network errors
     * and provides user-friendly error messages and logging.
     *
     * @param  GuzzleException  $exception  The network exception that occurred
     * @param  string  $method  HTTP method that was attempted
     * @param  string  $uri  URI that was requested
     */
    protected function handleNetworkError(GuzzleException $exception, string $method, string $uri): void
    {
        $errorType = 'network_error';
        $userMessage = 'Network error occurred while communicating with payment provider';
        $context = [
            'method' => $method,
            'uri' => $uri,
            'provider' => $this->getName(),
            'error_class' => $exception::class,
        ];

        if ($exception instanceof ConnectException) {
            $errorType = 'connection_error';
            $userMessage = 'Unable to connect to payment provider. Please check your internet connection and try again.';
            $context['error_type'] = 'connection_failure';
            $context['hint'] = 'This could be due to network timeout, DNS resolution failure, or the payment provider being temporarily unavailable.';
        } elseif ($exception instanceof ServerException) {
            $errorType = 'server_error';
            $userMessage = 'Payment provider server error. Please try again later.';
            $response = $exception->getResponse();
            $context['status_code'] = $response->getStatusCode();
            $context['response_body'] = (string) $response->getBody();
        } elseif ($exception instanceof RequestException) {
            $errorType = 'request_error';
            $userMessage = 'Request to payment provider failed. Please check your request and try again.';
            $response = $exception->getResponse();
            if ($response instanceof ResponseInterface) {
                $context['status_code'] = $response->getStatusCode();
            }
        } elseif ($exception instanceof TransferException) {
            $errorType = 'transfer_error';
            $userMessage = 'Data transfer error occurred. Please try again.';
        }

        $this->log('error', "Network error during $method request to $uri", array_merge($context, [
            'error_message' => $exception->getMessage(),
            'error_type' => $errorType,
            'user_message' => $userMessage,
        ]));
    }

    /**
     * Get a user-friendly error message from a GuzzleException.
     *
     * This method provides better error messages for different types of network errors,
     * making it easier for users to understand what went wrong.
     *
     * @param  GuzzleException  $exception  The network exception
     * @return string User-friendly error message
     */
    protected function getNetworkErrorMessage(GuzzleException $exception): string
    {
        if ($exception instanceof ConnectException) {
            return 'Unable to connect to payment provider. This may be due to a network timeout, connection issue, or the provider being temporarily unavailable. Please try again.';
        }

        if ($exception instanceof ServerException) {
            $response = $exception->getResponse();
            $statusCode = $response->getStatusCode();
            if (HttpStatusCodes::isServerError($statusCode)) {
                return 'Payment provider server error. The provider is experiencing issues. Please try again later.';
            }
        }

        if ($exception instanceof RequestException) {
            $response = $exception->getResponse();
            if ($response instanceof ResponseInterface) {
                $statusCode = $response->getStatusCode();
                if ($statusCode === HttpStatusCodes::TOO_MANY_REQUESTS) {
                    return 'Too many requests. Please wait a moment and try again.';
                }
                if (HttpStatusCodes::isClientError($statusCode)) {
                    return 'Invalid request to payment provider. Please check your payment details and try again.';
                }
            }
        }

        return 'Network error occurred while processing payment. Please check your connection and try again.';
    }
}
