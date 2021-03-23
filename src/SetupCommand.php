<?php declare(strict_types=1);

namespace KateGray\DnsChallenge;

use \Exception;
use \Cloudflare\API\Auth\APIKey;
use \Cloudflare\API\Adapter\Guzzle;
use \Cloudflare\API\Endpoints\DNS;
use \Cloudflare\API\Endpoints\Zones;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SetupCommand extends Command {
    protected static $defaultName = 'setup';

    protected function configure()
    {
        $this->setDescription ('Adds an ACME challenge to a CloudFlare zone')
             ->setHelp('This command takes the provided zone and acme domain, ' .
                 'makes an API call to Cloudflare, and adds the required record.')
             ->addArgument('zone', InputArgument::REQUIRED,"Zone (domain name)")
             ->addArgument('challenge', InputArgument::REQUIRED, 'ACME Challenge');
    }

    /**
     * Set up the DNS entry
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int Exit code for the command line interface
     * @throws \Cloudflare\API\Endpoints\EndpointException
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /** @var Application $application */
        $application = $this->getApplication();
        $config = $application->getConfig();

        $api_account = $config['cloudflare']['account'];
        $api_key     = $config['cloudflare']['api_key'];
        $record_name = $config['dns']['record_name'];
        $record_type = $config['dns']['record_type'];
        $zone_name   = $input->getArgument('zone');
        $challenge   = $input->getArgument('challenge');

        // Generate an API Key object and instantiate the API endpoints
        $key     = new APIKey($api_account, $api_key);
        $adapter = new Guzzle($key);
        $zones   = new Zones($adapter);
        $dns     = new DNS($adapter);

        // Concatenate the record to the zone name (required for API)
        $record = sprintf ('%s.%s', $record_name, $zone_name);

        // Look up the zone
        $zone_id = $zones->getZoneID($zone_name);
        if (!$zone_id) {
            throw new Exception('Unable to get ID for zone.');
        }

        // Check for an existing record
        $record_id = $dns->getRecordID($zone_id, $record_type, $record);
        var_dump ($record_id);
        if ('' != $record_id) {
            // Existing record, delete
            $result = $dns->deleteRecord($zone_id, $record_id);

            if (!$result) {
                throw new Exception ('Unable to delete record from Cloudflare.');
            }
        }

        if (false !== $challenge) {
            // Create a new record
            $result = $dns->addRecord($zone_id, $record_type, $record, $challenge, 120, false);
        }

        // Pause here in order to give cloudflare a chance before this kicks in
        sleep(5);

        // True if both the challenge and delete succeed
        if (true === $result) {
            $output->writeln('Record update <info>successful</info>.');
            return Command::SUCCESS;
        } else {
            throw new Exception ('Unable to perform operation.');
        }
    }
}