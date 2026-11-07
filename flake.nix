{
  description = "Pront PHP property-based testing library development environment";

  inputs = {
    nixpkgs.url = "github:NixOS/nixpkgs/nixpkgs-unstable";
  };

  outputs = { self, nixpkgs, ... }:
    let
      systems = [
        "aarch64-darwin"
        "aarch64-linux"
        "x86_64-darwin"
        "x86_64-linux"
      ];
      forAllSystems = nixpkgs.lib.genAttrs systems;
    in
    {
      devShells = forAllSystems (system:
        let
          pkgs = import nixpkgs { inherit system; };
          # PHP 8.5 is the floor (ADR-0014). Composer is built against the same PHP.
          php = pkgs.php85;
          composer = pkgs.php85Packages.composer;
        in
        {
          default = pkgs.mkShell {
            packages = [
              php
              composer
              pkgs.git
              pkgs.gh
              pkgs.gnumake
            ];
          };
        });
    };
}
