<?php
declare(strict_types=1);

namespace Yaroslavche\TDLibBundle\TDLib;

use RuntimeException;
use Symfony\Component\OptionsResolver\OptionsResolver;
use TDApi\LogConfiguration;
use TDApi\TDLibParameters;
use TDLib\JsonClient;
use Yaroslavche\TDLibBundle\Exception\InvalidArgumentException;
use Yaroslavche\TDLibBundle\Exception\InvalidAuthenticationCodeException;
use Yaroslavche\TDLibBundle\Exception\InvalidDatabaseEncryptionKeyException;
use Yaroslavche\TDLibBundle\Exception\InvalidPhoneNumberException;
use Yaroslavche\TDLibBundle\Exception\InvalidResponseException;
use Yaroslavche\TDLibBundle\Exception\InvalidTdlibParametersException;
use Yaroslavche\TDLibBundle\TDLib\Response\UpdateAuthorizationState;
use Yaroslavche\TDLibBundle\TDLib\Response\UpdateOption;

abstract class AbstractJsonClient
{
    /** @var JsonClient $jsonClient */
    private $jsonClient;
    /** @var OptionsResolver $optionsResolver */
    private $optionsResolver;
    /** @var string[]|bool[]|int[] $tdlibParameters */
    private $tdlibParameters;
    /** @var string[]|bool[]|int[] $clientConfig */
    private $clientConfig;
    /** @var string[]|bool[]|int[] $options */
    private $options;
    /** @var string|null $authorizationState */
    private $authorizationState;

    /**
     * @param string[]|int[]|bool[] $tdlibParameters
     * @param string[]|int[]|bool[] $clientConfig
     * @throws InvalidArgumentException
     * @throws InvalidAuthenticationCodeException
     * @throws InvalidDatabaseEncryptionKeyException
     * @throws InvalidPhoneNumberException
     * @throws InvalidResponseException
     * @throws InvalidTdlibParametersException
     * @todo pass RequestStack into constructor?
     * AbstractClient constructor.
     */
    public function __construct(array $tdlibParameters, array $clientConfig)
    {
        $this->optionsResolver = new OptionsResolver();
        $this->tdlibParameters = $this->resolve($tdlibParameters, [
            TDLibParameters::USE_TEST_DC => true,
            TDLibParameters::DATABASE_DIRECTORY => '/var/tmp/tdlib',
            TDLibParameters::FILES_DIRECTORY => '/var/tmp/tdlib',
            TDLibParameters::USE_FILE_DATABASE => true,
            TDLibParameters::USE_CHAT_INFO_DATABASE => true,
            TDLibParameters::USE_MESSAGE_DATABASE => true,
            TDLibParameters::USE_SECRET_CHATS => true,
            TDLibParameters::API_ID => null,
            TDLibParameters::API_HASH => null,
            TDLibParameters::SYSTEM_LANGUAGE_CODE => 'en',
            TDLibParameters::DEVICE_MODEL => php_uname('s'),
            TDLibParameters::SYSTEM_VERSION => php_uname('v'),
            TDLibParameters::APPLICATION_VERSION => '0.0.1',
            TDLibParameters::ENABLE_STORAGE_OPTIMIZER => true,
            TDLibParameters::IGNORE_FILE_NAMES => true,
        ]);
        /**
         * @todo
         *      - check if directories exists and accessible and will be used
         *      - enable debug if test_dc true?
         *      - check other important (?)
         */
        $this->clientConfig = $this->resolve($clientConfig, [
            'phone_number' => null,
            'encryption_key' => '',
            'default_timeout' => 0.5,
            'auto_init' => true
        ]);
        if (false !== $this->clientConfig['auto_init']) {
            $this->initJsonClient($clientConfig['phone_number']);
        }
    }

    /**
     * @param string $type
     * @param mixed[] $params
     * @param float|null $timeout
     * @return ResponseInterface
     * @throws InvalidArgumentException
     * @throws InvalidResponseException
     */
    public function query(string $type, array $params = [], ?float $timeout = null): ResponseInterface
    {
        $query = json_encode(array_merge(['@type' => $type], $params));
        if (!$query) {
            throw new InvalidArgumentException();
        }
        /** @todo invalid timeout parameter */
        $rawResponse = $this->jsonClient->query($query); //, $timeout ?? $this->clientConfig['default_timeout']);
        $response = AbstractResponse::fromRaw($rawResponse);
        $responseClass = sprintf('%s\\Response\\%s', __NAMESPACE__, ucfirst($response->getType()));
        if (class_exists($responseClass)) {
            $response = new $responseClass($rawResponse);
        }
        return $response;
    }

    /**
     * @param string $name
     * @return bool|int|string|null
     */
    public function getOption(string $name)
    {
        return $this->options[$name];
    }

    /**
     * @param string|null $phoneNumber
     * @param bool|null $force
     * @throws InvalidArgumentException
     * @throws InvalidAuthenticationCodeException
     * @throws InvalidDatabaseEncryptionKeyException
     * @throws InvalidPhoneNumberException
     * @throws InvalidResponseException
     * @throws InvalidTdlibParametersException
     */
    public function initJsonClient(?string $phoneNumber = null, ?bool $force = null): void
    {
        if (!$force && $this->jsonClient instanceof JsonClient) {
            return;
        }
        $this->jsonClient = new JsonClient();
        $this->jsonClient->setDefaultTimeout(floatval($this->clientConfig['default_timeout']));
        /** set tdlib parameters */
        $setParametersResponse = $this->query('setTdlibParameters', [
            'parameters' => $this->tdlibParameters,
        ]);
        if ($setParametersResponse->getType() !== 'ok') {
            throw new InvalidTdlibParametersException();
        }
        /** set database encryption key */
        $setEncryptionKeyResult = $this->query('setDatabaseEncryptionKey', [
            'new_encryption_key' => $this->clientConfig['encryption_key'] ?? ''
        ]);
        if ($setEncryptionKeyResult->getType() !== 'ok') {
            throw new InvalidDatabaseEncryptionKeyException();
        }
        /** check all received responses */
        $this->handleResponses();

        if (null !== $phoneNumber && $this->authorizationState === UpdateAuthorizationState::AUTHORIZATION_STATE_WAIT_PHONE_NUMBER) {
            $setAuthenticationPhoneNumberResponse = $this->query('setAuthenticationPhoneNumber', [
                'phone_number' => $phoneNumber
            ]);
            if ($setAuthenticationPhoneNumberResponse->getType() !== 'ok') {
                throw new InvalidPhoneNumberException();
            }
            $this->handleResponses();
        }

        if ($this->authorizationState === UpdateAuthorizationState::AUTHORIZATION_STATE_WAIT_CODE) {
            $code = $_POST[sprintf('code_%s', $phoneNumber)] ?? null;
            if (null === $code) {
                throw new RuntimeException(sprintf('Please set authentication code for %s', $phoneNumber));
            }
            $checkAuthenticationCodeResponse = $this->query('checkAuthenticationCode', [
                'code' => $code
            ]);
            if ($checkAuthenticationCodeResponse->getType() !== 'ok') {
                throw new InvalidAuthenticationCodeException();
            }
        }
    }

    /**
     * @param mixed[] $options
     * @param mixed[] $defaults
     * @return mixed[]
     */
    protected function resolve(array $options, array $defaults = []): array
    {
        $this->optionsResolver->clear();
        $this->optionsResolver->setDefaults($defaults);
        return $this->optionsResolver->resolve($options);
    }

    /**
     * @throws InvalidArgumentException
     * @throws InvalidResponseException
     */
    protected function handleResponses(): void
    {
        $responses = $this->jsonClient->getReceivedResponses();
        foreach ($responses as $rawResponse) {
            $responseObject = AbstractResponse::fromRaw($rawResponse);
            switch ($responseObject->getType()) {
                case 'updateOption':
                    $this->updateOption(UpdateOption::fromRaw($rawResponse));
                    break;
                case 'updateAuthorizationState':
                    $this->updateAuthorizationState(UpdateAuthorizationState::fromRaw($rawResponse));
                    break;
                default:
                    break;
            }
        }
    }

    /**
     * @param ResponseInterface $updateOptionResponse
     * @throws InvalidArgumentException
     */
    protected function updateOption(ResponseInterface $updateOptionResponse): void
    {
        if (!$updateOptionResponse instanceof UpdateOption) {
            throw new InvalidArgumentException();
        }
        $value = $updateOptionResponse->getValue();
        switch ($updateOptionResponse->getValueType()) {
            case UpdateOption::OPTION_VALUE_INTEGER:
                $value = (int)$value;
                break;
            case UpdateOption::OPTION_VALUE_BOOLEAN:
                $value = (bool)$value;
                break;
            case UpdateOption::OPTION_VALUE_STRING:
            default:
                break;
        }
        $this->options[$updateOptionResponse->getName()] = $value;
    }

    /**
     * @param ResponseInterface $updateAuthorizationStateResponse
     * @throws InvalidArgumentException
     */
    protected function updateAuthorizationState(ResponseInterface $updateAuthorizationStateResponse): void
    {
        if (!$updateAuthorizationStateResponse instanceof UpdateAuthorizationState) {
            throw new InvalidArgumentException();
        }
        $this->authorizationState = $updateAuthorizationStateResponse->getAuthorizationState();
    }
}
