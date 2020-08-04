<p align="center">
  <img src="https://cdn.discordapp.com/attachments/514331305111191563/662292362223026216/SkyWarsForPE.png"/>
</p>

## SkyWarsForPE
[![Poggit-CI](https://poggit.pmmp.io/ci.shield/larryTheCoder/SkyWarsForPE/SkyWarsForPE)](https://poggit.pmmp.io/ci/larryTheCoder/SkyWarsForPE/SkyWarsForPE) [![Donate](https://img.shields.io/badge/donate-PayPal-yellow.svg?style=flat-square)](http://www.paypal.me/Permeable)

    Powered by Open Sourced code.
    Its time to burn those old fancy paid plugins and beat them in face!

## Introduction
**This plugin is unstable and inconsistent, it is not ready to be used, use with caution.**

SkyWarsForPE is a plugin built for all Minecraft PE Community, this source has been moved to open sourced again due to some inactivity in project, as the result, this plugin will be continued to compete with other SkyWars plugin. I ensure you, this plugin is more powerful than ever before. It's all for free!

## Implemented features:
- Asynchronous world loading/unloading. (Technically just fast)
- Setup arena with a FormAPI interface, its just easy.
- GUI handled setup and settings.
- Chest particles, effects, custom cloaks
- More commands, A lot of working commands.
- Easy built in Item handling configuration. _check config.yml_
- Incredible performance and fast startup.
- Saves world, load them, then restart them with style.
- Random Chests (To be done).
- Events, public API, Yes we welcome developers to come contribute.
- NPC Top winners, oh yes, this what been waiting for.
- Top winners! Top winners! Who won the game?
- All the player stats will be stored centrally in sql database.
- Provides the best settings for your server.
- Implemented scoreboards UI, it can be configured in [scoreboard.yml](https://github.com/larryTheCoder/SkyWarsForPE/blob/master/resources/scoreboard.yml).

### Planned Features
Giving out planned features won't do anything without trying first. These are the planned features for the plugin.

- Proxy based game (Connects from server to server)
- Chest randomized.
- Chest Open Close packet (See Hypixel game behaviour)
- Time manipulation
- Game API (Do some cool stuff and controls the arena)
- At the end of the round you will be placed in the gamemode 2 during the teleport.
- Configurable particles at the end of the round (adjustable in Config.yml)
- Implementation of Team Mode for SkyWars.
- Join title when entering a round (adjustable in Config.yml).
- In the GUI for the cages images support (In the cages.yml adjustable for example: Image: "https://fileupload.com/up/ggisdagdg")


## Commands

| Default command | Parameter | Description | Default Permission |
| :-----: | :-------: | :---------: | :-------: |
| /lobby | | back to lobby | `All` |
| /sw |`<args>` | Main SkyWars command | `All` |
| /sw help | | Get command map | `All` |
| /sw random | | Randomly join to an arena | `All` |
| /sw cage | | Set your own cage | `All` |
| /sw stats | | Show the player stats | `ALL`|
| /sw reload | | Reload the plugin | `OP` |
| /sw npc | | Creates a top winner NPC | `OP` |
| /sw create | `<Arena Name>` | create an arena for SkyWars | `OP` |
| /sw start | `<Arena Name>` | Start the game | `OP` |
| /sw stop | `<Arena Name>` | Stop an arena | `OP` |
| /sw join | `<Arena Name>` | join an arena | `All` |
| /sw settings | | Open settings GUI | `OP` |
| /sw setlobby | | Set the main lobby for the plugin | `OP` |

### License
Before you attempted to copy any of this plugin, please read following license before you continue.

    Adapted from the Wizardry License

    Copyright (c) 2015-2019 larryTheCoder and contributors

    Permission is hereby granted to any persons and/or organizations
    using this software to copy, modify, merge, publish, and distribute it.
    Said persons and/or organizations are not allowed to use the software or
    any derivatives of the work for commercial use or any other means to generate
    income, nor are they allowed to claim this software as their own.

    The persons and/or organizations are also disallowed from sub-licensing
    and/or trademarking this software without explicit permission from larryTheCoder.

    Any persons and/or organizations using this software must disclose their
    source code and have it publicly available, include this license,
    provide sufficient credit to the original authors of the project (IE: larryTheCoder),
    as well as provide a link to the original project.

    THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED,
    INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,FITNESS FOR A PARTICULAR
    PURPOSE AND NON INFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE
    LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT,
    TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE
    USE OR OTHER DEALINGS IN THE SOFTWARE.
