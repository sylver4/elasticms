<?php

namespace EMS\CoreBundle\Controller;

use Doctrine\ORM\EntityManager;
use EMS\CommonBundle\Contracts\SpreadsheetGeneratorServiceInterface;
use EMS\CommonBundle\Helper\EmsFields;
use EMS\CoreBundle\Core\DataTable\DataTableFactory;
use EMS\CoreBundle\Core\User\UserManager;
use EMS\CoreBundle\DataTable\Type\UserDataTableType;
use EMS\CoreBundle\Entity\AuthToken;
use EMS\CoreBundle\Entity\User;
use EMS\CoreBundle\Entity\WysiwygProfile;
use EMS\CoreBundle\Form\Form\TableType;
use EMS\CoreBundle\Form\Form\UserType;
use EMS\CoreBundle\Repository\WysiwygProfileRepository;
use EMS\CoreBundle\Routes;
use EMS\CoreBundle\Service\UserService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class UserController extends AbstractController
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly UserService $userService,
        private readonly UserManager $userManager,
        private readonly SpreadsheetGeneratorServiceInterface $spreadsheetGenerator,
        private readonly DataTableFactory $dataTableFactory,
        private readonly string $templateNamespace
    ) {
    }

    public function indexAction(Request $request): Response
    {
        $table = $this->dataTableFactory->create(UserDataTableType::class);

        $form = $this->createForm(TableType::class, $table);
        $form->handleRequest($request);

        return $this->render("@$this->templateNamespace/user/index.html.twig", [
            'form' => $form->createView(),
        ]);
    }

    public function addUserAction(Request $request): Response
    {
        $user = new User();

        /** @var EntityManager $em */
        $em = $this->getDoctrine()->getManager();

        /** @var WysiwygProfileRepository $repository */
        $repository = $em->getRepository(WysiwygProfile::class);
        $result = $repository->findBy([], ['orderKey' => 'asc'], 1);
        if (\count($result) > 0) {
            $user->setWysiwygProfile($result[0]);
        }

        $form = $this->createForm(UserType::class, $user, ['mode' => UserType::MODE_CREATE]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $continue = $this->userExist($user, 'add');

            if ($continue) {
                $user->setEnabled(true);
                $this->userManager->update($user);
                $this->addFlash('notice', 'User created!');

                return $this->redirectToRoute(Routes::USER_INDEX);
            }
        }

        return $this->render("@$this->templateNamespace/user/add.html.twig", [
            'form' => $form->createView(),
        ]);
    }

    public function editUserAction(User $id, Request $request): Response
    {
        return $this->edit($id, $request);
    }

    public function edit(User $user, Request $request): Response
    {
        $form = $this->createForm(UserType::class, $user, ['mode' => UserType::MODE_UPDATE]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->userService->updateUser($user);
            $this->logger->notice('log.user.updated', [
                'username_managed' => $user->getUsername(),
                'user_display_name' => $user->getDisplayName(),
                EmsFields::LOG_OPERATION_FIELD => EmsFields::LOG_OPERATION_UPDATE,
            ]);

            return $this->redirectToRoute(Routes::USER_INDEX);
        }

        return $this->render("@$this->templateNamespace/user/edit.html.twig", [
            'form' => $form->createView(),
            'user' => $user,
        ]);
    }

    public function removeUserAction(User $id): Response
    {
        return $this->delete($id);
    }

    public function delete(User $user): Response
    {
        $username = $user->getUsername();
        $displayName = $user->getDisplayName();
        $this->userService->deleteUser($user);
        $this->getDoctrine()->getManager()->flush();

        $this->logger->notice('log.user.deleted', [
            'username_managed' => $username,
            'user_display_name' => $displayName,
            EmsFields::LOG_OPERATION_FIELD => EmsFields::LOG_OPERATION_DELETE,
        ]);

        return $this->redirectToRoute(Routes::USER_INDEX);
    }

    public function enabling(User $user): Response
    {
        if ($user->isEnabled()) {
            $user->setEnabled(false);
            $message = 'log.user.disabled';
        } else {
            $user->setEnabled(true);
            $message = 'log.user.enabled';
        }

        $this->userService->updateUser($user);
        $this->getDoctrine()->getManager()->flush();

        $this->logger->notice($message, [
            'username_managed' => $user->getUsername(),
            'user_display_name' => $user->getDisplayName(),
            EmsFields::LOG_OPERATION_FIELD => EmsFields::LOG_OPERATION_UPDATE,
        ]);

        return $this->redirectToRoute(Routes::USER_INDEX);
    }

    public function apiKeyAction(string $username): Response
    {
        return $this->apiKey($username);
    }

    public function apiKey(string $username): Response
    {
        $user = $this->userService->giveUser($username, false);

        $roles = $user->getRoles();
        if (!\in_array('ROLE_API', $roles)) {
            $this->logger->error('log.user.cannot_request_api_key', [
                'user' => $username,
                'initiator' => $this->userService->getCurrentUser()->getUsername(),
            ]);

            throw new \RuntimeException(\sprintf('The user %s  does not have the permission to use API functionalities.', $username));
        }

        $authToken = new AuthToken($user);

        /** @var EntityManager $em */
        $em = $this->getDoctrine()->getManager();
        $em->persist($authToken);
        $em->flush();

        // TODO: Hide the key in the logs?
        $this->logger->notice('log.user.api_key', [
            'username_managed' => $user->getUsername(),
            'user_display_name' => $user->getDisplayName(),
            'api_key' => $authToken->getValue(),
            EmsFields::LOG_OPERATION_FIELD => EmsFields::LOG_OPERATION_UPDATE,
        ]);

        return $this->redirectToRoute(Routes::USER_INDEX);
    }

    public function sidebarCollapseAction(bool $collapsed): Response
    {
        $user = $this->userService->giveUser($this->userService->getCurrentUser()->getUsername(), false);
        $user->setSidebarCollapse($collapsed);

        /** @var EntityManager $em */
        $em = $this->getDoctrine()->getManager();
        $em->persist($user);
        $em->flush();

        return $this->render("@$this->templateNamespace/ajax/notification.json.twig", [
            'success' => true,
        ]);
    }

    public function spreadsheetExport(string $_format): Response
    {
        $rows = [['username', 'display name', 'notification', 'email', 'enabled', 'last login', 'roles']];
        foreach ($this->userService->getAll() as $user) {
            $lastLogin = $user->getLastLogin();
            if (null !== $lastLogin) {
                $lastLogin = $lastLogin->format('c');
            } else {
                $lastLogin = '';
            }
            $rows[] = [
                $user->getUsername(),
                $user->getDisplayName(),
                $user->getEmailNotification() ? 'Y' : 'N',
                $user->getEmail(),
                $user->isEnabled() ? 'Y' : 'N',
                $lastLogin,
                \implode(', ', $user->getRoles()),
            ];
        }

        return $this->spreadsheetGenerator->generateSpreadsheet([
            SpreadsheetGeneratorServiceInterface::SHEETS => [[
                'name' => 'Users',
                'rows' => $rows,
            ]],
            SpreadsheetGeneratorServiceInterface::CONTENT_FILENAME => 'users',
            SpreadsheetGeneratorServiceInterface::WRITER => $_format,
        ]);
    }

    private function userExist(User $user, string $action): bool
    {
        $exists = [
            'email' => $this->userManager->getUserByEmail($user->getEmail()),
            'username' => $this->userManager->getUserByUsername($user->getUsername()),
        ];
        $messages = ['email' => 'User email already exist!', 'username' => 'Username already exist!'];
        foreach ($exists as $key => $value) {
            if ($value instanceof User) {
                if ('add' === $action || ('edit' === $action && $value->getId() !== $user->getId())) {
                    $this->addFlash('error', $messages[$key]);

                    return false;
                }
            }
        }

        return true;
    }
}
