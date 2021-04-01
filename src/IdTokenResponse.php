<?php
/**
 * @author Steve Rhoades <sedonami@gmail.com>
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace OpenIDConnectServer;

use OpenIDConnectServer\Repositories\IdentityProviderInterface;
use OpenIDConnectServer\Entities\ClaimSetInterface;
use League\OAuth2\Server\Entities\UserEntityInterface;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\ResponseTypes\BearerTokenResponse;
use Lcobucci\JWT\Signer\Key;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Builder;

class IdTokenResponse extends BearerTokenResponse
{
    /**
     * @var IdentityProviderInterface
     */
    protected $identityProvider;

    /**
     * @var ClaimExtractor
     */
    protected $claimExtractor;

    /**
     * @var string
     */
    protected $nonce;

    /** @var string */
    protected $issuer;

    public function __construct(
        IdentityProviderInterface $identityProvider,
        ClaimExtractor $claimExtractor
    ) {
        $this->identityProvider = $identityProvider;
        $this->claimExtractor   = $claimExtractor;
    }

    /**
     * @param string $nonce
     * @return voice
     */
    public function setNonce(string $nonce): void
    {
        $this->nonce = $nonce;
    }

    /**
     * @param string $issuer
     * @return void
     */
    public function setIssuer(string $issuer): void
    {
        $this->issuer = $issuer;
    }

    protected function getBuilder(AccessTokenEntityInterface $accessToken, UserEntityInterface $userEntity)
    {
        // Add required id_token claims
        $builder = (new Builder())
            ->permittedFor($accessToken->getClient()->getIdentifier())
            ->issuedBy($this->issuer ?: 'https://' . $_SERVER['HTTP_HOST'])
            ->issuedAt(time())
            ->expiresAt($accessToken->getExpiryDateTime()->getTimestamp())
            ->relatedTo($userEntity->getIdentifier());

        return $builder;
    }

    /**
     * @param AccessTokenEntityInterface $accessToken
     * @return array
     */
    protected function getExtraParams(AccessTokenEntityInterface $accessToken)
    {
        if (false === $this->isOpenIDRequest($accessToken->getScopes())) {
            return [];
        }

        /** @var UserEntityInterface $userEntity */
        $userEntity = $this->identityProvider->getUserEntityByIdentifier($accessToken->getUserIdentifier());

        if (false === is_a($userEntity, UserEntityInterface::class)) {
            throw new \RuntimeException('UserEntity must implement UserEntityInterface');
        } elseif (false === is_a($userEntity, ClaimSetInterface::class)) {
            throw new \RuntimeException('UserEntity must implement ClaimSetInterface');
        }

        // Add required id_token claims
        $builder = $this->getBuilder($accessToken, $userEntity);

        // Need a claim factory here to reduce the number of claims by provided scope.
        $claims = $this->claimExtractor->extract($accessToken->getScopes(), $userEntity->getClaims());

        foreach ($claims as $claimName => $claimValue) {
            $builder = $builder->withClaim($claimName, $claimValue);
        }

        $builder->withClaim('client_id', $accessToken->getClient()->getIdentifier());
        $builder->withClaim('nonce', $this->nonce);

        $token = $builder
            ->getToken(new Sha256(), new Key($this->privateKey->getKeyPath(), $this->privateKey->getPassPhrase()));

        return [
            'id_token' => (string) $token
        ];
    }

    /**
     * @param ScopeEntityInterface[] $scopes
     * @return bool
     */
    private function isOpenIDRequest($scopes)
    {
        // Verify scope and make sure openid exists.
        $valid  = false;

        foreach ($scopes as $scope) {
            if ($scope->getIdentifier() === 'openid') {
                $valid = true;
                break;
            }
        }

        return $valid;
    }
}
