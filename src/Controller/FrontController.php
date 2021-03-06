<?php

namespace App\Controller;

use App\Entity\Achat;
use App\Entity\Articles;
use App\Entity\Commande;
use App\Repository\ArticlesRepository;
use App\Repository\CategorieRepository;
use App\Service\Panier\PanierService;
use DateTime;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Swift_Image;
use Swift_Mailer;
use Swift_Message;
use Swift_SmtpTransport;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class FrontController extends AbstractController
{
    // ---------- Page d'accueil -------------
    /**
     * @Route ("/", name="home")
     */
    public function home() : ?Response
    {
        return $this->render("front/home.html.twig");
    }

    // ---------- Page des articles -------------
    /**
     * @Route("/show_articles", name="show_articles")
     */
    public function show_articles(ArticlesRepository $repository,CategorieRepository $categorieRepository): Response
    {
        $categories=$categorieRepository->findBy([], ['nom' => 'ASC']);
        $articles= $repository->findBy([], ['nom' => 'ASC']);


        return $this->render('front/show_articles.html.twig', [
            'articles'=> $articles,
            'categories'=>$categories
        ]);
    }

    // ---------- Page d'un seul article -------------
    /**
     * @Route("/show/{id}", name="showarticle")
     */
    public function show(Articles $articles)
    {
        return $this->render('front/show.html.twig', [
            'article'=>$articles
        ]);
    }

    // ---------- Page du panier -------------
    /**
     * @Route("/panier", name="panier")
     */
    public function panier(PanierService $panierService, ArticlesRepository $articleRepository)
    {
        $articles = $articleRepository->findBy([], ['nom' => 'ASC']);

        return $this->render("front/panier.html.twig",[
            'items' => $panierService->getFullPanier(),
            'total' => $panierService->getTotal(),
            'articles'=>$articles
        ]);
    }

    // ---------- Page du profil -------------
    /**
     * @Route("/profil", name="profil")
     */
    public function profil() : ?Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        return $this->render('front/profil.html.twig');
    }

    // ---------- Page de la politique de confidentialit?? -------------
    /**
     * @Route("/politique-de-confidentialite", name="politique-de-confidentialite")
     */
    public function polDeConf() {
        return $this->render("front/politique_de_conf.html.twig");
    }

    // ---------- Page du FOOTER -------------
    /**
     * @Route("/a_propos", name="a_propos")
     */
    public function aPropos() {
        return $this->render('front/a_propos.html.twig');
    }

    /**
     * @Route("/all_magasins", name="all_magasins")
     */
    public function allMagasin() {
        return $this->render('front/all_magasin.html.twig');
    }

    /**
     * @Route("/tarifs", name="tarifs")
     */
    public function tarifs() {
        return $this->render('front/tarifs.html.twig');
    }

    /**
     * @Route("/promotions", name="promotions")
     */
    public function promotions() {
        return $this->render('front/promotions.html.twig');
    }


    // ----------- Passer une commande ------------
    /**
     * @Route("/commande", name="commande")
     */
    public function commande(PanierService $panierService, EntityManagerInterface $manager)
    {

//        /**
//         * fonction appelant le service panier afin de le transformer en commande,
//         * ainsi chaques articles avec leur quantit?? enregistr??s dans le panier correspondra ?? un achat.
//         * le cumul de tout ces achats aura un seule et m??me id de commande et cr??era donc une commande reli??e par l'id aux achats, eux m??mes reli??s aux articles en bdd
//         */

        // V??rifie si l'utilisateur est connect?? avec son compte google sans avoir saisie ses infos
        $user = $this->getUser();
        $userID = $user->getId();

        if($user->getUsername() == null ||
            $user->getPassword() == null ||
            $user->getAdresse() == null ||
            $user->getCp() == null ||
            $user->getNom() == null ||
            $user->getPrenom() == null) {

            $this->addFlash("success", "Veuillez compl??ter votre profil pour pouvoir commander");
            return $this->redirectToRoute("modifprofil", ['id' => $userID]);
            // Le renvoi sur la page edit profil
        }


        $panier = $panierService->getFullPanier();
        $commande = new Commande();

        $commande->setEtat("en attente de validation");
        $commande->setTotal($panierService->getTotal());
        $commande->setUser($this->getUser());

        foreach ($panier as $item) {
            $article=$item['article'];
            $achat = new Achat();
            $achat->setArticle($item['article']);
            $achat->setQuantite($item['quantite']);
            $achat->setPrix($item['article']->getPrix());
            $article->setStock($article->getStock()-$item['quantite']);
            $manager->persist($achat);
            $manager->persist($article);
            $achat->setCommande($commande);
            $panierService->delete($item['article']->getId());

        }

        $date = new DateTime();
        $commande->setDate($date);

        $manager->persist($commande);
        $manager->flush();
        $this->addFlash('success', 'Commande valid??e');

        return $this->redirectToRoute('home');
    }

    /**
     * @Route("/show_articles", name="show_articles")
     */
    public function gestionArticles(ArticlesRepository $repository, Request $request, CategorieRepository $categorieRepository)
    {
        // Initialise les vars n??cessaires ?? la recherche
        $introuvables = false;
        $articlesChoisi = null;

        // Effectue les test pour le trie avec la cat??gorie, le prix et le type
        if($request->query->get('type') && empty($request->query->get('prixInf'))) {
            $articlesChoisi = $repository->findBy(["categorie" => $request->query->get('categorie'), "type" => $request->query->get('type')], ["prix" => "ASC"]);
            if(empty($articlesChoisi)) {
                $introuvables = true;
            }
        }
        else if($request->query->get('type') && $request->query->get('prixInf')) {
            $articlesChoisi = $repository->findByPrixTypeCategorie($request->query->get('prixInf'),$request->query->get('type'),$request->query->get('categorie'));
            if(empty($articlesChoisi)) {
                $introuvables = true;
            }
        }
        // Effectue les test pour le trie avec la cat??gorie et le prix
        else if($request->query->get('categorie') && empty($request->query->get('prixInf'))) {
            $articlesChoisi = $repository->findBy(['categorie' => $request->query->get('categorie')], ["prix" => "ASC"]);
            if(empty($articlesChoisi)) {
                $introuvables = true;
            }
        }
        else if($request->query->get('categorie') && $request->query->get('prixInf')) {
            $articlesChoisi = $repository->findByPrixCategorie($request->query->get('prixInf'), $request->query->get('categorie'));
            if(empty($articlesChoisi)) {
                $introuvables = true;
            }
        }
        else if($request->query->get('prixInf') && empty($request->query->get('categorie'))) {
            $articlesChoisi = $repository->findByPrix($request->query->get('prixInf'));
            if(empty($articlesChoisi)) {
                $introuvables = true;
            }
        }
        // Si il n'as rien trouv?? alors initialise les vars ?? null
        else {
            $articlesChoisi = null;
        }

        // Renvoi les articles et cat??gorie trier par nom
        $articles= $repository->findBy([], ['nom' => 'ASC']);
        $categories = $categorieRepository->findBy([], ['nom' => 'ASC']);

        return $this->render('front/show_articles.html.twig',[
            'articles'=> $articles,
            'articlesChoisi' => $articlesChoisi,
            'introuvables' => $introuvables,
            'categories' => $categories
        ]);
    }


    // ----------------- PANIER -------------------------
    /**
     * @Route("/ajoutpanier/{id}/{param}", name="ajout_panier")
     */
    public function ajoutPanier($id, $param ,PanierService $panierService)
    {
        $panierService->add($id);

        if ($param=="show_articles"):
            return $this->redirectToRoute('show_articles');
        elseif ($param=="panier"):
            return $this->redirectToRoute('panier');
        endif;

    }

    /**
     * @Route("/retraitpanier/{id}", name="retrait_panier")
     */
    public function retraitPanier($id ,PanierService $panierService)
    {
        $panierService->remove($id);

        return $this->redirectToRoute('panier');
    }

    /**
     * @Route("/annulepanier/{id}", name="annule_panier")
     */
    public function annulePanier($id ,PanierService $panierService)
    {
        $panierService->delete($id);

        return $this->redirectToRoute('panier');
    }


    // ------- BAR DE RECHERCHE DANS LA NAV ----------
    /**
     * @Route("/searchrender", name="searchrender")
     */
    public function search(ArticlesRepository $repository, Request $request, CategorieRepository $categorieRepository)
    {
        $article = $repository->search($request->query->get('search'));
        $categories=$categorieRepository->findBy([], ['nom' => 'ASC']);
        // si la requ??te retourne un article, l'utilisateur est renvoy?? vers la page detail
        if (count($article) === 1){

            return $this->render('front/show.html.twig', [
                    'article' => $article[0]
                ]
            );
        }
        // si la requ??te ne retourne pas de article, l'utilisateur est renvoy?? vers une page
        // lui indiquant que sa requ??te n'a pas retourn?? de r??sultats
        elseif (count($article) === 0){
            $articles = $repository->findAll();
            $this->addFlash('echec', 'aucun article ne correspond ?? votre recherche');
            return $this->render('front/show_articles.html.twig', [
                    'articles' => $articles,
                    'categories' => $categories
                ]
            );
        }
        // si plusieurs articles correspondent ?? la requ??te, l'utilisateur est renvoy?? vers le tableau
        // pr??sentant ceux-ci
        else {
            $articles=$article;

            return $this->render('front/show_articles.html.twig', [
                    'articles' => $articles,
                    'categories'=>$categories
                ]
            );
        }

    }

    /**
     * @Route("/handle_search")
     * @return JsonResponse
     */
    public function autocomplete(Request $request)
    {
        $term = $request->query->get('query');

        $array = $this->getDoctrine()
            ->getManager()
            ->getRepository(Articles::class)
            // la m??thode pr??sente dans le repository Article est utilis??e ici en param??tre
            ->autocomplete($term);

        // le r??sultat est ensuite encod?? au format json pour l'appel en ajax
        return new JsonResponse($array);
    }

    // ---------------- EMAIL - NOUS CONTACTER ---------------------
    /**
     * @Route("/mail", name="mail")
     */
    public function send_email(request $request)
    {
        if (!empty($request->request)):
            // dd($request->request->get('email'));
            $transporter = (new Swift_SmtpTransport('smtp.gmail.com', 465, 'ssl'))
                ->setUsername('fast.caddie@gmail.com')
                ->setPassword('jadjou78');

            $mailer = new Swift_Mailer($transporter);
            $mess=$request->request->get('message');
            $nom=$request->request->get('surname');
            $prenom=$request->request->get('name');
            $motif=$request->request->get('need');


            $message = (new Swift_Message("$motif"))
                ->setFrom($request->request->get('email'))
                ->setTo(['fast.caddie@gmail.com'=> 'Fast caddie']);
            $cid = $message->embed(Swift_Image::fromPath('assets/images/logoFastCaddie.png'));
            $message->setBody(

                $this->renderView('Email/mailReturn.html.twig',[
                    'message'=>$mess,
                    'nom'=>$nom,
                    'prenom'=>$prenom,
                    'motif'=>$motif,
                    'email'=>$request->request->get('email'),
                    'cid'=>$cid
                ]),
                'text/html'
            );

            $result = $mailer->send($message);
            $this->addFlash('success', 'email envoy??');
            return $this->redirectToRoute('home');
        endif;
    }

    /**
     * @Route("/sendform", name="send_form")
     */
    public function form_email()
    {
        return $this->render('Email/mailForm.html.twig');
    }
}
