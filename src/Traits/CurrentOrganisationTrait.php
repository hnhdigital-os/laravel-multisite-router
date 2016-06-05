<?php
namespace MultiSiteRouter\Traits;

use MultiSiteRouter\Scopes\CurrentOrganisationScope;

trait CurrentOrganisationTrait 
{
    /**
     * Boot the current organisation trait for a model.
     *
     * @return void
     */
    public static function bootCurrentOrganisationTrait()
    {
        static::addGlobalScope(new CurrentOrganisationScope);
    }
}
