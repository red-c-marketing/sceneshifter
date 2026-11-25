<?php

declare(strict_types=1);

namespace RedC\Sceneshifter\Tests\Unit;

use RedC\Sceneshifter\Registry\Registry;

final class Fixtures {

	public static function registry(): Registry {
		return Registry::fromArray(
			array(
				'version' => 3,
				'sites'   => array(
					array(
						'id'      => 'live',
						'label'   => 'Live',
						'role'    => 'live',
						'url'     => 'https://www.client.co.uk',
						'aliases' => array( 'client.co.uk', 'cdn.client.co.uk' ),
					),
					array(
						'id'    => 'staging',
						'label' => 'Staging',
						'role'  => 'staging',
						'url'   => 'https://staging.client.co.uk',
					),
					array(
						'id'     => 'dev',
						'label'  => 'Dev',
						'role'   => 'dev',
						'url'    => 'http://client.test:8080',
						'public' => false,
					),
					array(
						'id'    => 'uat',
						'label' => 'UAT',
						'role'  => 'uat',
						'url'   => 'https://agency.example.com/clients/client',
					),
				),
			)
		);
	}
}
