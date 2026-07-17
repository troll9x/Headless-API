<?php
namespace TLU_Headless_API\Contracts;

/**
 * Base interface for all REST API endpoints in the framework.
 *
 * Endpoints are responsible for defining their route, method,
 * arguments, and handling the request logic.
 */
interface Endpoint {
	public function get_route(): string;
	public function get_method(): string;
	public function permission_callback(): callable;
	public function get_args(): array;
	public function handle( \WP_REST_Request $request );
}