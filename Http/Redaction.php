<?php

namespace Omnibank\Http;

/**
 * What a failed call may carry of its credentials, taken out before the
 * failure leaves the package - omnisense/contracts' Redaction under
 * omnibank's name: secrets() collects a request's credentials, text() masks
 * them (and every key parameter, every URL's user part) as ***, copy() gives
 * a copy of the HTTP client's exception to wrap (same class and code, its
 * trace without arguments), withoutArguments() takes the arguments out of a
 * failure's trace.
 */
final class Redaction extends \Omnisense\Contracts\Http\Redaction
{
}
