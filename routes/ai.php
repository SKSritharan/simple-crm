<?php

use App\Mcp\Servers\CrmServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::local('crm', CrmServer::class);
